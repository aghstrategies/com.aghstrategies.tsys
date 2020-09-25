<?php

use CRM_Tsys_ExtensionUtil as E;

/**
 * Payment Processor class for Tsys
 *
 * copied from Payment Processor class for Stripe
 */
class CRM_Core_Payment_TsysDevice extends CRM_Core_Payment_Tsys {

 public static function curlapicall($url) {
    $ch = curl_init();
    //http://php.net/manual/en/function.curl-setopt.php

    curl_setopt($ch, CURLOPT_URL, $url);
    // curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    // curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_ENCODING, "");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    // for debugging?
    // curl_setopt($ch, CURLOPT_VERBOSE, true);
    $data = curl_exec($ch);

    curl_close($ch);
    $obj = json_decode($data);
    return $obj;
  }

}
