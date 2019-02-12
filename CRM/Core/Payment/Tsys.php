<?php
/*
 * Payment Processor class for Stripe
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
   $this->_islive = ($mode == 'live' ? 1 : 0);
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
    // $config = CRM_Core_Config::singleton();
    $error = array();
    // TODO fix this up to be Tsys specific
    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "Secret Key" is not set in the Tsys Payment Processor settings.');
    }
    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('The "Publishable Key" is not set in the Tsys Payment Processor settings.');
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
    // Get API Key and provide it to JS
    $paymentProcessorId = CRM_Utils_Array::value('id', $form->_paymentProcessor);
    $publishableKey = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($paymentProcessorId, "password");
    $publishableKey = $publishableKey['password'];
    CRM_Core_Resources::singleton()->addVars('tsys', array('api' => $publishableKey));
  }

  /**
   * Given a payment processor id, return the publishable key (password field)
   *
   * @param $paymentProcessorId
   *
   * @return string
   */
  public static function getPaymentProcessorSettings($paymentProcessorId, $fields) {
   try {
     $publishableKey = civicrm_api3('PaymentProcessor', 'getsingle', array(
       'return' => $fields,
       'id' => $paymentProcessorId,
     ));
   }
   catch (CiviCRM_API3_Exception $e) {
     return '';
   }
   return $publishableKey;
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
    // Get contribution Statuses
    $failedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
    $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');

    // Check if the contribution uses non us dollars
    if ($params['currencyID'] != 'USD') {
      CRM_Core_Error::statusBounce(ts('Tsys only works with USD, Contribution not processed'));
      Civi::log()->debug('Tsys Contribution attempted using currency besides USD.  Report this message to the site administrator. $params: ' . print_r($params, TRUE));
      $params['payment_status_id'] = $failedStatusId;
      return $params;
    }

    // TODO generate a better trxn_id
    // cannot use invoice id in civi because it needs to be less than 8 numbers and all numeric.
    $params['trxn_id'] = rand(1, 1000000);

    // TODO decide if we need these params
    // $params['fee_amount'] = $stripeBalanceTransaction->fee / 100;
    // $params['net_amount'] = $stripeBalanceTransaction->net / 100;

    // Get tsys credentials
    if (!empty($params['payment_processor_id'])) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($params['payment_processor_id'], array("signature", "subject", "user_name"));
    }

    // Throw an error if no credentials found
    if (empty($tsysCreds)) {
      CRM_Core_Error::statusBounce(ts('No valid payment processor credentials found'));
      Civi::log()->debug('No valid Tsys credentials found.  Report this message to the site administrator. $params: ' . print_r($params, TRUE));
    }

    // If there is a payment token use it to run the transaction
    if (!empty($params['payment_token']) && $params['payment_token'] != "Authorization token")  {
      // Make transaction
      $makeTransaction = CRM_Core_Payment_Tsys::composeSaleSoapRequestToken(
        $params['payment_token'],
        $tsysCreds,
        $params['amount'],
        $params['trxn_id']
      );
    }
    // IF no Payment Token look for credit card fields
    else {
      if (!empty($params['credit_card_number']) &&
      !empty($params['cvv2']) &&
      !empty($params['credit_card_exp_date']['M']) &&
      !empty($params['credit_card_exp_date']['Y'])) {
      $creditCardInfo = array(
          'credit_card' => $params['credit_card_number'],
          'cvv' => $params['cvv2'],
          'exp' => $params['credit_card_exp_date']['M'] . $params['credit_card_exp_date']['Y'],
          'AvsStreetAddress' => '',
          'AvsZipCode' => '',
          'CardHolder' => "{$params['billing_first_name']} {$params['billing_last_name']}",
        );
        if (!empty($params['billing_street_address-' . $params['location_type_id']])) {
          $creditCardInfo['AvsStreetAddress'] = $params['billing_street_address-' . $params['location_type_id']];
        }
        if (!empty($params['billing_postal_code-' . $params['location_type_id']])) {
          $creditCardInfo['AvsZipCode'] = $params['billing_postal_code-' . $params['location_type_id']];
        }
        $makeTransaction = CRM_Core_Payment_Tsys::composeSaleSoapRequestCC(
          $creditCardInfo,
          $tsysCreds,
          $params['amount'],
          $params['trxn_id']
        );
      }
      // If no credit card fields throw an error
      else {
        CRM_Core_Error::statusBounce(ts('Unable to complete payment, missing credit card info! Please this to the site administrator with a description of what you were trying to do.'));
        Civi::log()->debug('Tsys unable to complete this transaction!  Report this message to the site administrator. $params: ' . print_r($params, TRUE));
      }
    }
    // If transaction approved
    if (!empty($makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus) &&
    $makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus  == "APPROVED") {
      $params['payment_status_id'] = $completedStatusId;
      if (!empty($params['payment_token'])) {
        $query = "SELECT COUNT(vault_token) FROM civicrm_tsys_recur WHERE vault_token = %1";
        $queryParams = array(1 => array($params['payment_token'], 'String'));
        // If transaction is recurring AND there is not an existing vault token saved, create a boarded card and save it
        if (CRM_Utils_Array::value('is_recur', $params) && CRM_Core_DAO::singleValueQuery($query, $queryParams) == 0 && !empty($params['contributionRecurID'])) {
          CRM_Core_Payment_Tsys::boardCard($params['contributionRecurID'], $makeTransaction->Body->SaleResponse->SaleResult->Token, $tsysCreds);
        }
      }
      return $params;
    }
    // If transaction fails
    else {
      $params['payment_status_id'] = $failedStatusId;
      return $params;
    }
  }
  /**
   * This is a recurring donation, save the card for future use
   * @param  [type] $params    [description]
   * @param  [type] $token     [description]
   * @param  [type] $tsysCreds [description]
   * @return [type]            [description]
   */
  public static function boardCard($recur_id, $token, $tsysCreds) {
    // Board Card (save card) with TSYS
    $boardCard = CRM_Core_Payment_Tsys::composeBoardCardSoapRequest(
      $token,
      $tsysCreds
    );
    // IF card boarded successfully save the vault token to the database
    if (!empty($boardCard->Body->BoardCardResponse->BoardCardResult->VaultToken)) {
      // Save token in civi Database
      $query_params = array(
        1 => array($boardCard->Body->BoardCardResponse->BoardCardResult->VaultToken, 'String'),
        2 => array($recur_id, 'Integer'),
      );
      CRM_Core_DAO::executeQuery("INSERT INTO civicrm_tsys_recur (vault_token, recur_id) VALUES (%1, %2)", $query_params);
    }
    // If no vault token record Error
    else {
      CRM_Core_Error::statusBounce(ts('Card not saved for future use'));
      Civi::log()->debug('Credit Card not boarded to Tsys Error Message: ' . print_r($boardCard->Body->BoardCardResponse->BoardCardResult->ErrorMessage, TRUE));
    }
  }

  /**
   * composes soap request with token and sends it to tsys
   * @param  [type] $token [description]
   * @return [type]        [description]
   */
  public static function composeSaleSoapRequestToken($token, $tsysCreds, $amount, $trxnID) {
    $soap_request = <<<HEREDOC
<?xml version="1.0"?>
    <soap:Envelope xmlns:soap='http://www.w3.org/2003/05/soap-envelope'>
       <soap:Body>
          <Sale xmlns='http://schemas.merchantwarehouse.com/merchantware/v45/'>
             <Credentials>
                <MerchantName>{$tsysCreds['user_name']}</MerchantName>
                <MerchantSiteId>{$tsysCreds['subject']}</MerchantSiteId>
                <MerchantKey>{$tsysCreds['signature']}</MerchantKey>
             </Credentials>
             <PaymentData>
                <Source>Vault</Source>
                <VaultToken>{$token}</VaultToken>
              </PaymentData>
             <Request>
                <Amount>$amount</Amount>
                <CashbackAmount>0.00</CashbackAmount>
                <SurchargeAmount>0.00</SurchargeAmount>
                <TaxAmount>0.00</TaxAmount>
                <InvoiceNumber>$trxnID</InvoiceNumber>
             </Request>
          </Sale>
       </soap:Body>
    </soap:Envelope>
HEREDOC;
    return $response = CRM_Core_Payment_Tsys::doSoapRequest($soap_request);
  }

  /**
   * composes soap request with credit card and send it to tsys
   * @param  [type] $token [description]
   * @return [type]        [description]
   */
  public static function composeSaleSoapRequestCC($cardInfo, $tsysCreds, $amount, $trxnID) {
    $soap_request = <<<HEREDOC
<?xml version="1.0"?>
    <soap:Envelope xmlns:soap='http://www.w3.org/2003/05/soap-envelope'>
       <soap:Body>
          <Sale xmlns='http://schemas.merchantwarehouse.com/merchantware/v45/'>
             <Credentials>
                <MerchantName>{$tsysCreds['user_name']}</MerchantName>
                <MerchantSiteId>{$tsysCreds['subject']}</MerchantSiteId>
                <MerchantKey>{$tsysCreds['signature']}</MerchantKey>
             </Credentials>
             <PaymentData>
               <Source>Keyed</Source>
               <CardNumber>{$cardInfo['credit_card']}</CardNumber>
               <ExpirationDate>{$cardInfo['exp']}</ExpirationDate>
               <CardHolder>{$cardInfo['CardHolder']}</CardHolder>
               <AvsStreetAddress>{$cardInfo['AvsStreetAddress']}</AvsStreetAddress>
               <AvsZipCode>{$cardInfo['AvsZipCode']}</AvsZipCode>
               <CardVerificationValue>{$cardInfo['cvv']}</CardVerificationValue>
            </PaymentData>
             <Request>
                <Amount>$amount</Amount>
                <CashbackAmount>0.00</CashbackAmount>
                <SurchargeAmount>0.00</SurchargeAmount>
                <TaxAmount>0.00</TaxAmount>
                <InvoiceNumber>$trxnID</InvoiceNumber>
             </Request>
          </Sale>
       </soap:Body>
    </soap:Envelope>
HEREDOC;
    return $response = CRM_Core_Payment_Tsys::doSoapRequest($soap_request);
  }

  public static function composeBoardCardSoapRequest($token, $tsysCreds) {
    $soap_request = <<<HEREDOC
<?xml version="1.0"?>
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
   <soap:Body>
      <BoardCard xmlns="http://schemas.merchantwarehouse.com/merchantware/v45/">
         <Credentials>
           <MerchantName>{$tsysCreds['user_name']}</MerchantName>
           <MerchantSiteId>{$tsysCreds['subject']}</MerchantSiteId>
           <MerchantKey>{$tsysCreds['signature']}</MerchantKey>
         </Credentials>
         <PaymentData>
            <Source>PREVIOUSTRANSACTION</Source>
            <Token>{$token}</Token>
         </PaymentData>
      </BoardCard>
   </soap:Body>
</soap:Envelope>
HEREDOC;
    return CRM_Core_Payment_Tsys::doSoapRequest($soap_request);
  }

  public static function doSoapRequest($soap_request) {
    $response = "NO RESPONSE";
    $header = array(
      "Content-type: text/xml;charset=\"utf-8\"",
      "Accept: text/xml",
      "Cache-Control: no-cache",
      "Pragma: no-cache",
      "Content-length: ".strlen($soap_request),
    );

    $soap_do = curl_init();
    curl_setopt($soap_do, CURLOPT_URL, "https://ps1.merchantware.net/Merchantware/ws/RetailTransaction/v45/Credit.asmx" );
    curl_setopt($soap_do, CURLOPT_CONNECTTIMEOUT, 20);
    curl_setopt($soap_do, CURLOPT_TIMEOUT,        20);
    curl_setopt($soap_do, CURLOPT_RETURNTRANSFER, true );
    curl_setopt($soap_do, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($soap_do, CURLOPT_SSL_VERIFYHOST, false);
    curl_setopt($soap_do, CURLOPT_POST,           true );
    curl_setopt($soap_do, CURLOPT_POSTFIELDS,     $soap_request);
    curl_setopt($soap_do, CURLOPT_HTTPHEADER,     $header);
    $response = curl_exec($soap_do);

    if ($response === false) {
      $err = 'Curl error: ' . curl_error($soap_do);
      curl_close($soap_do);
      print $err;
    }
    else {
      curl_close($soap_do);
      $response = str_ireplace(['SOAP-ENV:', 'SOAP:'], '', $response);
      $xml = simplexml_load_string($response);
    }
    return $xml;
  }
}
