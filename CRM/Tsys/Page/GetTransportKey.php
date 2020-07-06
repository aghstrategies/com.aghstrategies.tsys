<?php
use CRM_Tsys_ExtensionUtil as E;

class CRM_Tsys_Page_GetTransportKey extends CRM_Core_Page {

  public function run() {
    // If we have a transport key
    if (isset($_GET['tk'])) {
      $soap = 'report';
      $response['TransportKey'] = $_GET['tk'];
      // Get parameters from URL throw error if any are missing
      $urlParams = ['device' => NULL, 'tk' => NULL];
    }
    // If we do not have a transport key get one
    else {
      $soap = 'stage';
      $response['TransportKey'] = 0;
      // Get parameters from URL throw error if any are missing
      $urlParams = ['device' => NULL, 'amount' => NULL, 'test' => NULL];
    }

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
        if (!empty($deviceWeAreUsing['processorid'])) {
          $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($deviceWeAreUsing['processorid']);
          if (!empty($tsysCreds)) {
            if ($soap == 'stage') {
              $loggedInUser = CRM_Core_Session::singleton()->getLoggedInContactID();
              $response = CRM_Tsys_Soap::composeStageTransaction($tsysCreds, $urlParams['amount'], $loggedInUser, $deviceWeAreUsing['terminalid'], 0, $urlParams['test']);
              $response = CRM_Core_Payment_TsysDevice::processStageTransactionResponse($response);
            }
            elseif ($soap == 'report') {
              $response = CRM_Tsys_Soap::composeReportTransaction($tsysCreds, $urlParams['tk'], 0);
              $response = (array) $response;
            }
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
