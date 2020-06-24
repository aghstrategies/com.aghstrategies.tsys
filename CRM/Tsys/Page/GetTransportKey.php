<?php
use CRM_Tsys_ExtensionUtil as E;

class CRM_Tsys_Page_GetTransportKey extends CRM_Core_Page {

  public function run() {
    $response['TransportKey'] = 0;
    // Get parameters from URL throw error if any are missing
    $urlParams = ['device' => NULL, 'amount' => NULL, 'test' => NULL];
    $allRequiredFields = 1;
    foreach ($urlParams as $param => $value) {
      if (isset($_GET[$param])) {
        $urlParams[$param] = $_GET[$param];
      }
      else {
        $response['status'] = "Missing $param";
        $allRequiredFields = 0;
      }
    }

    // IF all required fields are present compose SOAP request to TSYS
    if ($allRequiredFields == 1) {
      $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('buttons');
      if (!empty($deviceSettings[$urlParams['device']])) {
        $deviceWeAreUsing = $deviceSettings[$urlParams['device']];
        $loggedInUser = CRM_Core_Session::singleton()->getLoggedInContactID();
        if (!empty($deviceWeAreUsing['processorid'])) {
          $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($deviceWeAreUsing['processorid']);
          if (!empty($tsysCreds)) {
            $response = CRM_Tsys_Soap::composeStageTransaction($tsysCreds, $urlParams['amount'], $loggedInUser, $deviceWeAreUsing['terminalid'], 0, $urlParams['test']);
            $response = CRM_Core_Payment_TsysDevice::processStageTransactionResponse($response);
          }
        }
        else {
          $response['status'] = "no processor ID";
        }
      }
      else {
        $response['status'] = "{$urlParams['device']} is not a valid Device";
      }
    }
    CRM_Core_Page_AJAX::returnJsonResponse($response);
  }
}
