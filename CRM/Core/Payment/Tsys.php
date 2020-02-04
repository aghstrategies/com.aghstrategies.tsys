<?php

use CRM_Tsys_ExtensionUtil as E;

/**
 * Payment Processor class for Tsys
 *
 * copied from Payment Processor class for Stripe
 */
class CRM_Core_Payment_Tsys extends CRM_Core_Payment {

  /**
   * We only need one instance of this object. So we use the singleton
   * pattern and cache the instance in this variable
   *
   * @var object
   * @static
   */
  static private $_singleton = NULL;

  /**
   * Mode of operation: live or test.
   *
   * @var object
   */
  protected $_mode = NULL;

  /**
   * TRUE if we are dealing with a live transaction
   *
   * @var boolean
   */
  private $_islive = FALSE;

  /**
   * can use the smartdebit processor on the backend
   * @return bool
   */
  public function supportsBackOffice() {
    return TRUE;
  }

  public function supportsRecurring() {
    return TRUE;
  }

  /**
   * can edit smartdebit recurring contributions
   * @return bool
   */
  public function supportsEditRecurringContribution() {
    return FALSE;
  }

  /**
   * Does this payment processor support refund?
   *
   * @return bool
   */
  public function supportsRefund() {
    return TRUE;
  }


  /**
   * Constructor
   *
   * @param string $mode
   *   The mode of operation: live or test.
   *
   * @param object $paymentProcessor
   *
   * @return void
   */
  public function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_islive = ($mode == 'live') ? 1 : 0;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('TSYS');
  }

  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   The error message if any.
   *
   * @public
   */
  public function checkConfig() {
    $error = array();
    $credFields = array(
      'user_name' => 'Merchant Name',
      'password' => 'Web API Key',
      'signature' => 'Merchant Site Key',
      'subject' => 'Merchant Site ID',
    );
    foreach ($credFields as $name => $label) {
      if (empty($this->_paymentProcessor[$name])) {
        $error[] = ts("The '%1' is not set in the TSYS Payment Processor settings.", array(1 => $label));
      }
    }
    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    // Don't use \Civi::resources()->addScriptFile etc as they often don't work on AJAX loaded forms (eg. participant backend registration)
    \Civi::resources()->addVars('tsys', [
      'allApiKeys' => CRM_Core_Payment_Tsys::getAllTsysPaymentProcessors(),
      'pp' => CRM_Utils_Array::value('id', $form->_paymentProcessor),
    ]);
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => \Civi::resources()->getUrl(E::LONG_NAME, "js/civicrm_tsys.js"),
    ]);
  }

  /**
   * Given a payment processor id, return details including publishable key
   *
   * @param int $paymentProcessorId
   * @return array
   */
  public static function getPaymentProcessorSettings($paymentProcessorId) {
    $fields = ["signature", "subject", "user_name", "is_test"];
    try {
      $paymentProcessorDetails = civicrm_api3('PaymentProcessor', 'getsingle', array(
        'return' => $fields,
        'id' => $paymentProcessorId,
      ));
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
      return [];
    }

    // Throw an error if credential not found
    foreach ($fields as $key => $field) {
      if (!isset($paymentProcessorDetails[$field])) {
        CRM_Core_Error::statusBounce(ts('Could not find valid TSYS Payment Processor credentials'));
        Civi::log()->debug("TSYS Credential $field not found.");
      }
    }
    return $paymentProcessorDetails;
  }

  /**
   * Get all Tsys payment processors and their web api keys
   * @return array of payment processor id => web api key
   */
  public static function getAllTsysPaymentProcessors() {
    $allTsysPaymentProcessors = array();
    try {
      $tsysPaymentProcessors = civicrm_api3('PaymentProcessorType', 'getsingle', [
        'title' => "TSYS",
        'api.PaymentProcessor.get' => [
          'payment_processor_type_id' => '$value.id',
          'return' => ['id', 'password'],
        ],
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }
    foreach ($tsysPaymentProcessors['api.PaymentProcessor.get']['values'] as $key => $processor) {
      if (!empty($processor['id']) && !empty($processor['password'])) {
        $allTsysPaymentProcessors[$processor['id']] = $processor['password'];
      }
    }
    return $allTsysPaymentProcessors;
  }

  /**
   * Get array of fields that should be displayed on the payment form for credit cards.
   *
   * @return array
   */
  protected function getCreditCardFormFields() {
    return array(
      'credit_card_type',
      'credit_card_number',
      'cvv2',
      'credit_card_exp_date',
      // ADD PAYMENT TOKEN
      'payment_token',
    );
  }

  /**
   * Check that the Currency is USD
   * @param array $params  contribution params
   * @return boolean       if Currency is USD
   */
  public function checkCurrencyIsUSD(&$params) {
    // when coming from a contribution form
    if (!empty($params['currencyID']) && $params['currencyID'] == 'USD') {
      return TRUE;
    }

    // when coming from a contribution.transact api call
    if (!empty($params['currency']) && $params['currency'] == 'USD') {
      return TRUE;
    }

    $currency = FALSE;
    try {
      $defaultCurrency = civicrm_api3('Setting', 'get', [
        'sequential' => 1,
        'return' => ["defaultCurrency"],
        'defaultCurrency' => "USD",
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }
    // look up the default currency, if its usd set this transaction to use us dollars
    if (!empty($defaultCurrency['values'][0]['defaultCurrency'])
    && $defaultCurrency['values'][0]['defaultCurrency'] == 'USD'
    && empty($params['currencyID'])
    && empty($params['currency'])) {
      $currency = TRUE;
    }
    return $currency;
  }

  /**
   * Process payment
   *
   * @param array $params
   *   Assoc array of input parameters for this transaction.
   *
   * @param string $component
   *
   * @return array
   *   Result array
   *
   * @throws \Civi\Payment\Exception\PaymentProcessorException
   */
  public function doPayment(&$params, $component = 'contribute') {
    // AGH #19994 if amount is 0 skip pinging tsys and just record a completed payment
    if ($params['amount'] == 0) {
      $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
      $params['payment_status_id'] = $params['contribution_status_id'] = $completedStatusId;
      return $params;
    }
    if (empty($params['invoice_number'])) {
      $params['invoice_number'] = rand(1, 9999999);
    }

    // Make sure using us dollars as the currency
    $currency = self::checkCurrencyIsUSD($params);

    // Get proper entry URL for returning on error.
    if (!(array_key_exists('qfKey', $params))) {
      // Probably not called from a civicrm form (e.g. webform) -
      // will return error object to original api caller.
      $params['stripe_error_url'] = NULL;
    }
    else {
      $qfKey = $params['qfKey'];
      $parsed_url = parse_url($params['entryURL']);
      $url_path = substr($parsed_url['path'], 1);
      $params['tsys_error_url'] = CRM_Utils_System::url($url_path,
      $parsed_url['query'] . "&_qf_Main_display=1&qfKey={$qfKey}", FALSE, NULL, FALSE);
    }

    // IF currency is not USD throw error and quit
    // Tsys does not accept non USD transactions
    if ($currency == FALSE) {
      $errorMessage = self::handleErrorNotification('TSYS only works with USD, Contribution not processed', $params['tsys_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException(' Failed to create TSYS Charge ' . $errorMessage);
    }

    // Get tsys credentials ($params come from a form)
    if (!empty($params['payment_processor_id'])) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($params['payment_processor_id']);
    }

    // Get tsys credentials ($params come from a Contribution.transact api call)
    if (!empty($params['payment_processor'])) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($params['payment_processor']);
    }

    // Throw an error if no credentials found
    if (empty($tsysCreds)) {
      $errorMessage = self::handleErrorNotification('No valid TSYS payment processor credentials found', $params['tsys_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create TSYS Charge: ' . $errorMessage);
    }
    if (!empty($params['payment_token']) && $params['payment_token'] != "Authorization token") {
      // If there is a payment token AND there is not a tsys_token use the payment token to run the transaction
      $token = $params['payment_token'];

      // If there is a previous transaction token ($params['tsys_token'])
      // board that card and use the vault token instead of the One Time
      // Transaction token ($params['payment_token']) which will no longer work
      // because it has already been used the one time
      if (!empty($params['tsys_token'])) {
        $boardCard = CRM_Tsys_Soap::composeBoardCardSoapRequest(
          $params['tsys_token'],
          $tsysCreds
        );
        if (!empty($boardCard->Body->BoardCardResponse->BoardCardResult->VaultToken)) {
          $token = (string) $boardCard->Body->BoardCardResponse->BoardCardResult->VaultToken;
        }
      }
      // Make transaction
      $makeTransaction = CRM_Tsys_Soap::composeSaleSoapRequestToken(
        $token,
        $tsysCreds,
        $params['amount'],
        $params['invoice_number']
      );
    }
    // DO NOT USE THIS This is for supporting testing with test card numbers
    elseif (!empty($params['credit_card_number']) &&
    !empty($params['cvv2']) &&
    !empty($params['credit_card_exp_date']['M']) &&
    !empty($params['credit_card_exp_date']['Y']) &&
    $params['unit_test'] == 1
    ) {
      $creditCardInfo = array(
        'credit_card' => $params['credit_card_number'],
        'cvv' => $params['cvv2'],
        'exp' => $params['credit_card_exp_date']['M'] . substr($params['credit_card_exp_date']['Y'], -2),
        'AvsStreetAddress' => '',
        'AvsZipCode' => '',
        'CardHolder' => "{$params['billing_first_name']} {$params['billing_last_name']}",
      );
      if (!empty($params['location_type_id'])) {
        if (!empty($params['billing_street_address-' . $params['location_type_id']])) {
          $creditCardInfo['AvsStreetAddress'] = $params['billing_street_address-' . $params['location_type_id']];
        }
        if (!empty($params['billing_postal_code-' . $params['location_type_id']])) {
          $creditCardInfo['AvsZipCode'] = $params['billing_postal_code-' . $params['location_type_id']];
        }
      }
      $makeTransaction = CRM_Tsys_Soap::composeSaleSoapRequestCC(
        $creditCardInfo,
        $tsysCreds,
        $params['amount'],
        $params['invoice_number']
      );
    }
    // If no payment token throw an error
    else {
      $errorMessage = self::handleErrorNotification('No Payment Token', $params['tsys_error_url']);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create TSYS Charge: ' . $errorMessage);
    }
    $params = self::processTransaction($makeTransaction, $params, $tsysCreds);
    return $params;
  }

  /**
   * Check if the vault token has ben saved to the database already
   * @param  int $paymentProcessor payment processor id
   * @param  string $vaultToken       vault token to check for
   * @return int                      number of tokens saved to the database
   */
  public static function checkForSavedVaultToken($paymentProcessor, $vaultToken) {
    $paymentTokenCount = 0;
    try {
      $paymentToken = civicrm_api3('PaymentToken', 'get', [
        'payment_processor_id' => $paymentProcessor,
        'token' => $vaultToken,
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }
    if (!empty($paymentToken['count'])) {
      $paymentTokenCount = $paymentToken['count'];
    }
    return $paymentTokenCount;
  }

  /**
   * After making the Tsys Soap Call, deal with the response
   * @param  object $makeTransaction response from tsys
   * @param  array $params           payment params
   * @param  array $tsysCreds        tsys Credentials
   * @return array $params           payment params updated to inculde relevant info from Tsys
   */
  public static function processTransaction($makeTransaction, &$params, $tsysCreds) {
    $failedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');

    // If transaction approved
    if (!empty($makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus)
    && $makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus == "APPROVED"
    && !empty($makeTransaction->Body->SaleResponse->SaleResult->Token)) {
      $params = self::processResponseFromTsys($params, $makeTransaction);
      // Successful contribution update the status and get the rest of the info from Tsys Response
      $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
      $params['payment_status_id'] = $completedStatusId;

      // Check if the token has been saved to the database
      $previousTransactionToken = (string) $makeTransaction->Body->SaleResponse->SaleResult->Token;
      $savedTokens = self::checkForSavedVaultToken($params['payment_processor_id'], $previousTransactionToken);

      // If transaction is recurring AND there is not an existing vault token saved, create a boarded card and save it
      if (CRM_Utils_Array::value('is_recur', $params)
      && $savedTokens == 0
      && !empty($params['contributionRecurID'])) {
        $paymentTokenId = CRM_Tsys_Recur::boardCard(
          $params['contributionRecurID'],
          $previousTransactionToken,
          $tsysCreds,
          $params['contactID'],
          $params['payment_processor_id']
        );
        $params['payment_token_id'] = $paymentTokenId;
      }
      return $params;
    }
    // If transaction fails
    else {
      $params['contribution_status_id'] = $failedStatusId;
      $errorMessage = 'TSYS rejected card ';
      if (!empty($params['error_message'])) {
        $errorMessage .= $params['error_message'];
      }
      if (isset($makeTransaction->Body->SaleResponse->SaleResult->ErrorMessage)) {
        $errorMessage .= $makeTransaction->Body->SaleResponse->SaleResult->ErrorMessage;
      }
      // If its a unit test return the params
      if (isset($params['unit_test']) && $params['unit_test'] == 1) {
        return $params;
      }
      $errorMessage = self::handleErrorNotification($errorMessage, $params['tsys_error_url'], $makeTransaction);
      throw new \Civi\Payment\Exception\PaymentProcessorException('Failed to create TSYS Charge: ' . $errorMessage);
    }
  }

  /**
   * Process XML from Tsys and add necessary things to the Contribution Params
   * @param  array $params           contribution params
   * @param  string $makeTransaction response from Tsys
   * @return array $params           updated params
   */
  public static function processResponseFromTsys(&$params, $makeTransaction) {
    $retrieveFromXML = [
      'trxn_id' => 'Token',
      'pan_truncation' => 'CardNumber',
      'card_type_id' => 'CardType',
      'tsys_token'  => 'Token',
      // NOTE the trxn_result coe is not saved
      // TODO fix core so that the trxn_result_code can be saved to the civicrm_finacial_trxn table using the api
      'trxn_result_code' => 'AuthorizationCode',

      // Not sure where to store these in civi
      'approval_status' => 'ApprovalStatus',
      'error_message' => 'ErrorMessage',

    ];

    // CardTypes as defined by tsys: https://docs.cayan.com/merchantware-4-5/credit#sale
    $tsysCardTypes = [
      4 => 'Visa',
      3 => 'MasterCard',
      1 => 'Amex',
      2 => 'Discover',
    ];
    foreach ($retrieveFromXML as $fieldInCivi => $fieldInXML) {
      if (isset($makeTransaction->Body->SaleResponse->SaleResult->$fieldInXML)) {
        $XMLvalueAsString = (string) $makeTransaction->Body->SaleResponse->SaleResult->$fieldInXML;
        switch ($fieldInXML) {
          case 'CardType':
            if (!empty($tsysCardTypes[$XMLvalueAsString])) {
              try {
                $cardType = civicrm_api3('OptionValue', 'getsingle', [
                  'sequential' => 1,
                  'return' => ["value"],
                  'option_group_id' => "accept_creditcard",
                  'name' => $tsysCardTypes[$XMLvalueAsString],
                ]);
              }
              catch (CiviCRM_API3_Exception $e) {
                $error = $e->getMessage();
                CRM_Core_Error::debug_log_message(ts('API Error %1', array(
                  'domain' => 'com.aghstrategies.tsys',
                  1 => $error,
                )));
              }
            }
            if (!empty($cardType['value'])) {
              $params[$fieldInCivi] = $cardType['value'];
            }
            break;

          case 'CardNumber':
            $params[$fieldInCivi] = substr($XMLvalueAsString, -4);
            break;

          default:
            $params[$fieldInCivi] = $XMLvalueAsString;
            break;
        }
      }
      else {
        CRM_Core_Error::debug_log_message(ts('Error retrieving %1 from XML', array(
          'domain' => 'com.aghstrategies.tsys',
          1 => $fieldInXML,
        )));
      }
    }
    return $params;
  }

  /**
   * Handle an error and notify the user
   * @param  string $errorMessage Error Message to be displayed to user
   * @param  string $bounceURL    Bounce URL
   * @param  string $makeTransaction response from TSYS
   * @return string               Error Message (or statusbounce if URL is specified)
   */
  public static function handleErrorNotification($errorMessage, $bounceURL = NULL, $makeTransaction = []) {
    Civi::log()->debug('TSYS Payment Error: ' . $errorMessage);
    if (!empty($makeTransaction)) {
      CRM_Core_Error::debug_var('makeTransaction', $makeTransaction);
    }
    if ($bounceURL) {
      CRM_Core_Error::statusBounce($errorMessage, $bounceURL, 'Payment Error');
    }
    return $errorMessage;
  }

  /**
   * support corresponding CiviCRM method
   */
  public function cancelSubscription(&$message = '', $params = array()) {
    $userAlert = ts('You have cancelled this recurring contribution.');
    CRM_Core_Session::setStatus($userAlert, ts('Warning'), 'alert');
    return TRUE;
  }

}
