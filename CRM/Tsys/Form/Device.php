<?php

use CRM_Tsys_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Tsys_Form_Device extends CRM_Core_Form {
  public function buildQuickForm() {

    // set up device select field
    $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('buttons');
    $deviceOptions = $this->getDeviceOptions($deviceSettings);
    $this->add(
      'select', // field type
      'device_id', // field name
      'Device', // field label
      $deviceOptions,
      TRUE // is required
    );

    // Set up other fields needed for transaction
    $this->addEntityRef('contact_id', E::ts('Select Contact'), [], TRUE);
    $this->addEntityRef('financial_type_id', E::ts('Financial Type'), [
      'entity' => 'FinancialType',
      'select' => ['minimumInputLength' => 0],
      'placeholder' => E::ts('- Select Financial Type -')
    ], TRUE);

    $this->addMoney('total_amount', E::ts("Total Amount"), TRUE, NULL, FALSE, 'currency', 'USD', TRUE);

    // Fields to save response from TSYS
    $this->add('text', 'tsys_initiate_response', "TSYS Initiate Response", NULL);
    $this->add('text', 'tsys_create_response', "TSYS Create Response", NULL);

    $this->addElement('checkbox', 'is_test', E::ts('Test transaction?'));

   // Set defaults
   $defaults = [];
   if ($_GET['cid']) {
     $defaults['contact_id'] = $_GET['cid'];
   }
   if ($_GET['deviceid']) {
     $defaults['device_id'] = $_GET['deviceid'];
   }
   $this->setDefaults($defaults);

   $this->addButtons([
       [
         'type' => 'cancel',
         'name' => E::ts('Cancel'),
       ],
       [
         'type' => 'submit',
         'name' => E::ts('Submit'),
       ]
     ]
   );

    // Set up cancel transaction while in progress
    $res = CRM_Core_Resources::singleton();

    $transportUrl = CRM_Utils_System::url('civicrm/tsys/transportkey', NULL, TRUE, NULL, FALSE, FALSE, FALSE);

    $res->addVars('tsys', [
      'ips' => $deviceSettings,
      'transport' => $transportUrl,
    ]);

    $res->addScriptFile('com.aghstrategies.tsys', 'js/deviceTransact.js');

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
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

  public function postProcess() {
    $values = $this->exportValues();
    if (!empty($values['tsys_create_response'])) {
      if (!empty($values['tsys_initiate_response'])) {
        $response = json_decode($values['tsys_initiate_response']);
      }
      $responseFromDevice = json_decode($values['tsys_create_response']);
      $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('buttons');
      if (!empty($deviceSettings[$values['device_id']])) {
        $deviceWeAreUsing = $deviceSettings[$values['device_id']];
        $params = CRM_Core_Payment_Tsys::processResponseFromTsys($values, $responseFromDevice, 'initiate');
        if ($responseFromDevice->TransactionType == 'SALE') {
          if ($responseFromDevice->Status == 'APPROVED') {
            // Clean up params so they have the needed items
            $params['currency'] = 'USD';
            $params['payment_processor_id'] = $params['payment_processor'] = $deviceWeAreUsing['processorid'];
            $params['payment_token'] = $params['token'] = $params['tsys_token'];
            $params['amount'] = $params['total_amount'] = $params['amount_approved'];
            $params['contribution_status_id'] = 'Pending';
            $params['source'] = " Credit Card Contribution via {$deviceWeAreUsing['devicename']} entry mode: {$params['entry_mode']}";

            // NOTE recording details for certification script in note field just for testing
            $params['note'] = "Transport Key = {$response->TransportKey}, Authorization Code = {$params['trxn_result_code']}, Token = {$params['tsys_token']}, Status = {$params['approval_status']}";

            // Make transaction - This is the way the docs say to make a contribution thru the api as of 5/13/20
            // Copied from https://docs.civicrm.org/dev/en/latest/financial/orderAPI/ 5/13/20
            try {
              $order = civicrm_api3('Order', 'create', $params);
            }
            catch (CiviCRM_API3_Exception $e) {
              $error = $e->getMessage();
              CRM_Core_Error::debug_log_message(E::ts('API Error %1', array(
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
              CRM_Core_Error::debug_log_message(E::ts('API Error %1', array(
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
                2 => $response->TransportKey,
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
              2 => $response->TransportKey,
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
