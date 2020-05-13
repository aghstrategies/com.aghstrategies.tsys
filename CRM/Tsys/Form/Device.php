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

    $this->add('text', 'total_amount', "Total Amount", TRUE);

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

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));

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
      $response = CRM_Tsys_Soap::composeStageTransaction($tsysCreds, $values['total_amount']);
      $response = CRM_Core_Payment_TsysDevice::processStageTransactionResponse($response);
      if (!empty($response['TransportKey'])) {
        $url = "http://{$deviceWeAreUsing['ip']}:8080/v1/pos?TransportKey={$response['TransportKey']}&Format=JSON";
        $responseFromDevice = CRM_Core_Payment_TsysDevice::curlapicall($url);
        if ($responseFromDevice->Status == 'APPROVED' && $responseFromDevice->TransactionType == 'SALE') {

          // Clean up params so they have the needed items
          $params = CRM_Core_Payment_Tsys::processResponseFromTsys($values, $responseFromDevice);
          $params['currency'] = 'USD';
          $params['payment_processor_id'] = $params['payment_processor'] = $deviceWeAreUsing['processorid'];
          $params['payment_token'] = $params['tsys_token'];
          $params['amount'] = $params['total_amount'];
          $params['contribution_status_id'] = 'Pending';
          $params['payment_instrument_id'] = "Credit Card";
          $params['source'] = "device {$deviceWeAreUsing['devicename']}";

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
            // Use the Payment Processor to attempt to take the actual payment. You may
            // pass in other params here, too.
            $pay = civicrm_api3('PaymentProcessor', 'pay', [
              'payment_processor_id' => $params['payment_processor_id'],
              'contribution_id' => $order['id'],
              'amount' => $params['total_amount'],
            ]);
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
