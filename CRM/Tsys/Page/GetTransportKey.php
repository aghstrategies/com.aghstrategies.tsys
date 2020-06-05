<?php
use CRM_Tsys_ExtensionUtil as E;

class CRM_Tsys_Page_GetTransportKey extends CRM_Core_Page {

  public function run() {
    // TODO add ifs for if things are missing etc basically expect anything that can go wrong will go wrong
    $device = $_GET['device'];
    $amount = $_GET['amount'];
    $test = $_GET['test'];
    $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('buttons');
    $deviceWeAreUsing = $deviceSettings[$device];
    $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($deviceWeAreUsing['processorid']);
    $loggedInUser = CRM_Core_Session::singleton()->getLoggedInContactID();

    $response = CRM_Tsys_Soap::composeStageTransaction($tsysCreds, $amount, $loggedInUser, $deviceWeAreUsing['terminalid'], 0, $test);
    $response = CRM_Core_Payment_TsysDevice::processStageTransactionResponse($response);
    CRM_Core_Page_AJAX::returnJsonResponse($response);
    return 'hi';
    // Example: Set the page-title dynamically; alternatively, declare a static title in xml/Menu/*.xml
    // CRM_Utils_System::setTitle(E::ts('GetTransportKey'));
    //
    // // Example: Assign a variable for use in a template
    // $this->assign('currentTime', date('Y-m-d H:i:s'));
    //
    // parent::run();
    // return;
  }

}
