<?php

use CRM_Tsys_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Tsys_Form_Device extends CRM_Core_Form {
  public function buildQuickForm() {
    $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('buttons');
    $deviceOptions = $this->getDeviceOptions($deviceSettings);
    $this->add(
      'select', // field type
      'device_id', // field name
      'Device', // field label
      $deviceOptions,
      TRUE // is required
    );

    $this->addEntityRef('contact_id', ts('Select Contact'), [], TRUE);
    $this->addEntityRef('financial_type_id', ts('Financial Type'), [
      'entity' => 'FinancialType',
      'select' => ['minimumInputLength' => 0],
    ], TRUE);

    $this->add('text', 'total_amount', "Total Amount", NULL, TRUE);
    $this->addElement('checkbox', 'is_test', ts('Test transaction?'));

   // Set defaults
   $defaults = [];
   if ($_GET['cid']) {
     $defaults['contact_id'] = $_GET['cid'];
   }
   if ($_GET['deviceid']) {
     $defaults['device_id'] = $_GET['deviceid'];
   }
   $this->setDefaults($defaults);

   // 'receive_date' => "",

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ],
      [
        'type' => 'cancel',
        'name' => E::ts('Cancel'),
      ],
    ]);

    // Set up cancel transaction while in progress
    $res = CRM_Core_Resources::singleton();
    $res->addScriptFile('com.aghstrategies.tsys', 'js/cancelDevice.js');
    $res->addVars('tsys', ['ips' => $deviceSettings]);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    // TODO need to fixup ids of devices so they are unique even if there are multiple processors with devices
    $values = $this->exportValues();
    $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('buttons');
    if (!empty($deviceSettings[$values['device_id']])) {
      $deviceWeAreUsing = $deviceSettings[$values['device_id']];
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($deviceWeAreUsing['processorid']);
      $loggedInUser = CRM_Core_Session::singleton()->getLoggedInContactID();

      $response = CRM_Tsys_Soap::composeStageTransaction($tsysCreds, $values['total_amount'], $loggedInUser, $deviceWeAreUsing['terminalid'], 0, $values['is_test']);

      $response = CRM_Core_Payment_TsysDevice::processStageTransactionResponse($response);
      if (!empty($response['TransportKey'])) {
        $url = "http://{$deviceWeAreUsing['ip']}:8080/v1/pos?TransportKey={$response['TransportKey']}&Format=JSON";
        if ($values['is_test'] == 1) {
          $url = "http://certeng-test.getsandbox.com/pos?TransportKey={$response['TransportKey']}&Format=JSON";
          $params['is_test'] = 1;
        }
        $responseFromDevice = CRM_Core_Payment_TsysDevice::curlapicall($url);
        $params = CRM_Core_Payment_Tsys::processResponseFromTsys($values, $responseFromDevice, 'initiate');
        if ($responseFromDevice->TransactionType == 'SALE') {
          if ($responseFromDevice->Status == 'APPROVED') {
            // Clean up params so they have the needed items
            $params['currency'] = 'USD';
            $params['payment_processor_id'] = $params['payment_processor'] = $deviceWeAreUsing['processorid'];
            $params['payment_token'] = $params['token'] = $params['tsys_token'];
            $params['amount'] = $params['total_amount'] = $params['amount_approved'];
            $params['contribution_status_id'] = 'Pending';
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
            }
            try {
              // Assuming the payment was taken, record it which will mark the Contribution
              // as Completed and update related entities.
              $paymentCreate = civicrm_api3('Payment', 'create', [
                'contribution_id' => $order['id'],
                'total_amount' => $params['amount'],
                'payment_instrument_id' => $params['payment_instrument_id'],
                // If there is a processor, provide it:
                'payment_processor_id' => $params['payment_processor_id'],
                'trxn_id' => $params['payment_token'],
                'pan_truncation' => $params['pan_truncation'],
                'card_type_id' => $params['card_type_id'],
              ]);
            }
            catch (CiviCRM_API3_Exception $e) {
              $error = $e->getMessage();
              CRM_Core_Error::debug_log_message(ts('API Error %1', array(
                'domain' => 'com.aghstrategies.tsys',
                1 => $error,
              )));
            }
            parent::postProcess();
            $viewContribution = CRM_Utils_System::url('civicrm/contact/view/contribution', "reset=1&id={$order['id']}&cid={$params['contact_id']}&action=view");
            CRM_Utils_System::redirect($viewContribution);
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
  }

  public function getDeviceOptions($deviceSettings) {
    $options = [];
    foreach ($deviceSettings as $key => $value) {
      if (!empty($value['devicename'])) {
        $options[$key] = $value['devicename'];
      }
    }
    return $options;
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
