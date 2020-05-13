<?php
/*
 * Class for Tsys SOAP calls
 */
class CRM_Tsys_Soap {

  /**
   * Compose Soap Request using a Tsys Token
   * @param  string $token         payment token
   * @param  array  $tsysCreds     payment processor credentials
   * @param  int    $amount        transaction amount
   * @param  int    $invoiceNumber invoice number
   * @return                       response from tsys
   */
  public static function composeSaleSoapRequestToken($token, $tsysCreds, $amount, $invoiceNumber = 0) {
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
                <InvoiceNumber>$invoiceNumber</InvoiceNumber>
             </Request>
          </Sale>
       </soap:Body>
    </soap:Envelope>
HEREDOC;
    $response = self::doSoapRequest($soap_request, $tsysCreds['is_test']);
    return $response;
  }

  /**
   * Soap request using credit card (currently only run when testing)
   * @param  array  $cardInfo      credit card number, exp, cardholder etc.
   * @param  array  $tsysCreds     payment processor credentials
   * @param  int    $amount        transaction amount
   * @param  int    $invoiceNumber invoice number
   * @return                       response from tsys
   */
  public static function composeSaleSoapRequestCC($cardInfo, $tsysCreds, $amount, $invoiceNumber = 0) {
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
                <InvoiceNumber>$invoiceNumber</InvoiceNumber>
             </Request>
          </Sale>
       </soap:Body>
    </soap:Envelope>
HEREDOC;
    $response = self::doSoapRequest($soap_request, $tsysCreds['is_test']);
    return $response;
  }

  /**
   * Board Card to Tsys -> Save Card to Tsys so it can be used for recurring transactions
   * @param  string $token    token generated from first transaction
   * @param  array $tsysCreds payment processor credentials
   * @return                  response from tsys
   */
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
    $response = self::doSoapRequest($soap_request);
    return $response;
  }

  /**
   * Un Board Card to Tsys -> Delete Card from Tsys so
   * @param  string $token    token generated from first transaction
   * @param  array $tsysCreds payment processor credentials
   * @return                  response from tsys
   */
  public static function composeUnBoardCardSoapRequest($token, $tsysCreds) {
    $soap_request = <<<HEREDOC
<?xml version="1.0"?>
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
   <soap:Header/>
   <soap:Body>
      <UnboardCard xmlns="http://schemas.merchantwarehouse.com/merchantware/v45/">
         <Credentials>
           <MerchantName>{$tsysCreds['user_name']}</MerchantName>
           <MerchantSiteId>{$tsysCreds['subject']}</MerchantSiteId>
           <MerchantKey>{$tsysCreds['signature']}</MerchantKey>
         </Credentials>
         <Request>
            <VaultToken>{$token}</VaultToken>
         </Request>
      </UnboardCard>
   </soap:Body>
</soap:Envelope>
HEREDOC;
    $response = self::doSoapRequest($soap_request);
    return $response;
  }


  /**
   * Issue Refund
   * @param  string $token    token generated from first transaction
   * @param  float $amount    amount to be refunded
   * @param  array $tsysCreds payment processor credentials
   * @return                  response from tsys
   */
  public static function composeRefundCardSoapRequest($token, $amount, $tsysCreds) {
    $soap_request = <<<HEREDOC
<?xml version="1.0"?>
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
   <soap:Body>
      <Refund xmlns="http://schemas.merchantwarehouse.com/merchantware/v45/">
         <Credentials>
           <MerchantName>{$tsysCreds['user_name']}</MerchantName>
           <MerchantSiteId>{$tsysCreds['subject']}</MerchantSiteId>
           <MerchantKey>{$tsysCreds['signature']}</MerchantKey>
         </Credentials>
         <PaymentData>
            <Source>PreviousTransaction</Source>
            <!--Previous Transaction Field-->
            <Token>$token</Token>
         </PaymentData>
         <Request>
            <Amount>$amount</Amount>
         </Request>
      </Refund>
   </soap:Body>
</soap:Envelope>
HEREDOC;
    $response = self::doSoapRequest($soap_request);
    return $response;
  }

  /**
   * Void Payment
   * @param  string $token    token generated from first transaction
   * @param  array $tsysCreds payment processor credentials
   * @return                  response from tsys
   */
  public static function composeVoidSoapRequest($token, $tsysCreds) {
    $soap_request = <<<HEREDOC
<soap:Envelope xmlns:soap="http://www.w3.org/2003/05/soap-envelope">
   <soap:Header/>
   <soap:Body>
      <Void xmlns="http://schemas.merchantwarehouse.com/merchantware/v45/">
         <Credentials>
         <MerchantName>{$tsysCreds['user_name']}</MerchantName>
         <MerchantSiteId>{$tsysCreds['subject']}</MerchantSiteId>
         <MerchantKey>{$tsysCreds['signature']}</MerchantKey>
         </Credentials>
         <Request>
            <Token>$token</Token>
         </Request>
      </Void>
   </soap:Body>
</soap:Envelope>
HEREDOC;
    $response = self::doSoapRequest($soap_request);
    return $response;
  }

  /**
   * Check if this payment can be refunded/for how much
   * @param  string $token    token generated from first transaction
   * @param  array $tsysCreds payment processor credentials
   * @return                  response from tsys
   */
  public static function composeCheckBalanceSoapRequest($token, $tsysCreds) {
    $soap_request = <<<HEREDOC
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
    xmlns:rep="http://schemas.merchantwarehouse.com/merchantware/40/Reports/">
      <soap:Body>
        <DetailedTransactionByReference
         xmlns="http://schemas.merchantwarehouse.com/merchantware/40/Reports/">
          <merchantName>{$tsysCreds['user_name']}</merchantName>
          <merchantSiteId>{$tsysCreds['subject']}</merchantSiteId>
          <merchantKey>{$tsysCreds['signature']}</merchantKey>
          <token>$token</token>
        </DetailedTransactionByReference>
      </soap:Body>
    </soap:Envelope>
HEREDOC;
    $response = self::doSoapRequest($soap_request, 0, 1);
    return $response;
  }



  /**
   * Execute SOAP Request and Parse Response
   * @param  string $soap_request SOAP Request
   * @return                response from tsys
   */
  public static function doSoapRequest($soap_request, $test = 0, $report = 0) {
    if ($test == 0) {
      $endpointURL = "https://ps1.merchantware.net/Merchantware/ws/RetailTransaction/v45/Credit.asmx";
    }
    if ($test == 1) {
      $endpointURL = "http://certeng-test.getsandbox.com/Merchantware/ws/RetailTransaction/v45/Credit.asmx";
    }
    if ($report == 1) {
      $endpointURL = "https://ps1.merchantware.net/Merchantware/ws/TransactionHistory/v4/Reporting.asmx";
    }
    $response = "NO RESPONSE";
    $header = array(
      "Content-type: text/xml;charset=\"utf-8\"",
      "Accept: text/xml",
      "Cache-Control: no-cache",
      "Pragma: no-cache",
      "Content-length: ".strlen($soap_request),
    );

    $soap_do = curl_init();
    curl_setopt($soap_do, CURLOPT_URL, $endpointURL);
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
      // print $err;
      $xml = $err;
    }
    else {
      curl_close($soap_do);
      $response = str_ireplace(['SOAP-ENV:', 'SOAP:'], '', $response);
      $xml = simplexml_load_string($response);
    }
    return $xml;
  }

}
