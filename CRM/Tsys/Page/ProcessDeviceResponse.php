<?php
use CRM_Tsys_ExtensionUtil as E;

class CRM_Tsys_Page_ProcessDeviceResponse extends CRM_Core_Page {

  public function run() {
    $values = [];
    $valuesToPullFromURL = [
      'test'=> 'is_test',
      'device' => 'device_id',
      'amount' => 'total_amount',
      'fintype' => 'financial_type_id',
      'contact' => 'contact_id',
    ];
    foreach ($valuesToPullFromURL as $urlParam => $fieldNameInCivi) {
      if (isset($_GET[$urlParam])) {
        $values[$fieldNameInCivi] = $_GET[$urlParam];
      } else {
        // TODO error missing required field
      }
    }

    $responseFromDevice = json_decode($_GET['json']);
    $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('buttons');
    $deviceWeAreUsing = $deviceSettings[$values['device_id']];

    $params = CRM_Core_Payment_Tsys::processResponseFromTsys($values, $responseFromDevice, 'initiate');

    if ($responseFromDevice->TransactionType == 'SALE') {
      if ($responseFromDevice->Status == 'APPROVED') {
        // Clean up params so they have the needed items
        $params['currency'] = 'USD';
        $params['payment_processor_id'] = $params['payment_processor'] = $deviceWeAreUsing['processorid'];
        $params['payment_token'] = $params['token'] = $params['tsys_token'];
        $params['payment_instrument_id'] = "Credit Card";

        // TODO record if payment instrument is a debit card
        $params['source'] = " Credit Card Contribution via {$deviceWeAreUsing['devicename']} entry mode: {$params['entry_mode']}";

        // NOTE record details for certification script in note field
        $params['note'] = "Transport Key = {$response['TransportKey']}, Authorization Code = {$params['trxn_result_code']}, Token = {$params['tsys_token']}, Status = {$params['approval_status']}";

        // Make transaction - This is the way the docs say to make a contribution thru the api as of 5/13/20
        // Copied from https://docs.civicrm.org/dev/en/latest/financial/orderAPI/ 5/13/20
        try {
          $order = civicrm_api3('Order', 'create', $params);
        }
        catch (CiviCRM_API3_Exception $e) {
          $error = $e->getMessage();
          CRM_Core_Error::debug_log_message(ts('API Error %1', array(
            'domain' => 'com.aghstrategies.tsys',
            1 => $error,
          )));

          CRM_Core_Session::setStatus(
            E::ts('error: %1', [
              1 => $error,
            ]),
            "Order Creation Failed",
            'error'
          );
        }
        if (!empty($order['id'])) {
          $viewContribution = CRM_Utils_System::url('civicrm/contact/view/contribution', "reset=1&id={$order['id']}&cid={$params['contact_id']}&action=view");
          // CRM_Core_Session::singleton()->pushUserContext($viewContribution);
          CRM_Core_Page_AJAX::returnJsonResponse($viewContribution);
          // CRM_Utils_System::redirect($viewContribution);
        }
        else {
          CRM_Core_Session::setStatus(
            E::ts('Order not created'),
            "Cancelled",
            'error'
          );
        }
      }
      else {
        CRM_Core_Session::setStatus(
          E::ts('error: %1, Transport Key: %2, Authorization Code: %3, Token: %4, Status: %5', [
            1 => $params['error_message'],
            2 => $response['TransportKey'],
            3 => $params['trxn_result_code'],
            4 => $params['tsys_token'],
            5 => $params['approval_status'],
          ]),
          "Transaction Failed",
          'error'
        );
      }
    }
    elseif ($responseFromDevice->Status == 'UserCancelled') {
      CRM_Core_Session::setStatus(
        E::ts('User Cancelled this transaction.'),
        "Cancelled",
        'error'
      );
    }
    elseif ($responseFromDevice->Status == 'POSCancelled') {
      CRM_Core_Session::setStatus(
        E::ts('You Cancelled this transaction.'),
        "Cancelled",
        'error'
      );
    }
    elseif (!empty($params['message']) && !empty($params['error_code'])) {
      CRM_Core_Session::setStatus(
        E::ts('error code: %1, Message: %3, Transport Key: %2', [
          1 => $params['error_code'],
          2 => $response['TransportKey'],
          3 => $params['message'],
        ]),
        "Transaction Failed",
        'error'
      );
    }
    else {
      CRM_Core_Session::setStatus(
        E::ts('Perhaps you have Invalid credentials or the wrong TransportKey'),
        "Something went wrong",
        'error'
      );
    }

  }
}
