<?php
/*
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
  * Constructor
  *
  * @param string $mode
  *   The mode of operation: live or test.
  *
  * @return void
  */
  public function __construct($mode, &$paymentProcessor) {
    $this->_mode = $mode;
    $this->_islive = ($mode == 'live') ? 1 : 0;
    $this->_paymentProcessor = $paymentProcessor;
    $this->_processorName = ts('Tsys');
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
        $error[] = ts("The '%1' is not set in the Tsys Payment Processor settings.", array(1 => $label));
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

    // Get all tsys payment processor ids keyed to their webapikeys
    $allTsysWebApiKeys = CRM_Core_Payment_Tsys::getAllTsysPaymentProcessors();
    CRM_Core_Resources::singleton()->addVars('tsys', array('allApiKeys' => $allTsysWebApiKeys));

    // send current payment processor
    $paymentProcessorId = CRM_Utils_Array::value('id', $form->_paymentProcessor);
    CRM_Core_Resources::singleton()->addVars('tsys', array('pp' => $paymentProcessorId));
  }

  /**
   * Given a payment processor id, return details including publishable key
   *
   * @param int $paymentProcessorId
   * @param array $fields
   * @return array
   */
  public static function getPaymentProcessorSettings($paymentProcessorId, $fields) {
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
     if (empty($paymentProcessorDetails[$field])) {
       CRM_Core_Error::statusBounce(ts('Could not find valid Tsys Payment Processor credentials'));
       Civi::log()->debug("Tsys Credential $field not found.");
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
        'title' => "Tsys",
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
    // when coming from a contribution form
    if (!empty($params['currencyID']) && $params['currencyID'] == 'USD') {
      $currency = TRUE;
    }

    // when coming from a contribution.transact api call
    if (!empty($params['currency']) && $params['currency'] == 'USD') {
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
    $params['invoice_number'] = rand(1, 1000000);

    // Get failed contribution status id
    $failedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');

    // Make sure using us dollars as the currency
    $currency = self::checkCurrencyIsUSD($params);

    // IF currency is not USD throw error and quit
    // Tsys does not accept non USD transactions
    if ($currency == FALSE) {
      CRM_Core_Error::statusBounce(ts('Tsys only works with USD, Contribution not processed'));
      Civi::log()->debug('Tsys Contribution attempted using currency besides USD.  Report this message to the site administrator. $params: ' . print_r($params, TRUE));
      $params['payment_status_id'] = $failedStatusId;
      return $params;
    }

    // Get tsys credentials ($params come from a form)
    if (!empty($params['payment_processor_id'])) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($params['payment_processor_id'], array("signature", "subject", "user_name"));
    }

    // Get tsys credentials ($params come from a Contribution.transact api call)
    if (!empty($params['payment_processor'])) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($params['payment_processor'], array("signature", "subject", "user_name"));
    }

    // Throw an error if no credentials found
    if (empty($tsysCreds)) {
      CRM_Core_Error::statusBounce(ts('No valid payment processor credentials found'));
      Civi::log()->debug('No valid Tsys credentials found.  Report this message to the site administrator. $params: ' . print_r($params, TRUE));
      $params['payment_status_id'] = $failedStatusId;
      return $params;
    }
    // If there is a payment token use it to run the transaction
    if (!empty($params['payment_token']) && $params['payment_token'] != "Authorization token")  {
      // Make transaction
      $makeTransaction = CRM_Tsys_Soap::composeSaleSoapRequestToken(
        $params['payment_token'],
        $tsysCreds,
        $params['amount'],
        $params['invoice_number']
      );
    }
    // If no payment token throw an error
    else {
      CRM_Core_Error::statusBounce(ts('Unable to complete payment, no tsys payment token! Please this to the site administrator with a description of what you were trying to do.'));
      Civi::log()->debug('Tsys unable to complete this transaction!  Report this message to the site administrator. $params: ' . print_r($params, TRUE));
      $params['payment_status_id'] = $failedStatusId;
      return $params;
    }
    $params = self::processTransaction($makeTransaction, $params, $tsysCreds);
    return $params;
  }

  /**
   * Check if the vault token has ben saved to the database already
   * @param  int    $paymentProcessor payment processor id
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
    $params = self::processResponseFromTsys($params, $makeTransaction);

    // If transaction approved
    if (!empty($makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus)
    && $makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus  == "APPROVED"
    && !empty($makeTransaction->Body->SaleResponse->SaleResult->Token)) {
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
      }
      return $params;
    }
    // If transaction fails
    else {
      $failedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
      // CRM_Core_Error::statusBounce(ts("Tsys Contribution Failed"));
      Civi::log()->debug('Credit Card Transaction Failed: ' . print_r($makeTransaction, TRUE));
      $params['payment_status_id'] = $failedStatusId;
      // TODO Process Failed response from Tsys
      return $params;
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
      // The trxn_result_code is not being saved to the civicrm_finacial_trxn table
      // not does it show up in the ui. For now I am tacking it on to the trxn_id so we can see it
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
      } else {
        CRM_Core_Error::debug_log_message(ts('Error retrieving %1 from XML', array(
          'domain' => 'com.aghstrategies.tsys',
          1 => $fieldInXML,
        )));
      }
    }
    if (!empty($params['trxn_result_code'])) {
      $params['trxn_id'] .= " {$params['trxn_result_code']}";
    }
    return $params;
  }
}
