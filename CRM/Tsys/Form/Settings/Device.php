<?php

use CRM_Tsys_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Tsys_Form_Settings_Device extends CRM_Core_Form {

  public function preProcess() {
    // DELETE Device
    if ($this->_action && !empty($_GET['id']) && $this->_action == CRM_Core_Action::DELETE) {
      $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('all');
      if (!empty($deviceSettings[$_GET['id']])) {
        unset($deviceSettings[$_GET['id']]);
      }
      try {
        $tsysProcesors = civicrm_api3('Setting', 'create', [
          'tsys_devices' => $deviceSettings,
        ]);
      }
      catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        CRM_Core_Error::debug_log_message(ts('API Error %1', array(
          'domain' => 'com.aghstrategies.tsys',
          1 => $error,
        )));
      }
      $tsysSettingsForm = CRM_Utils_System::url('civicrm/tsyssettings');
      CRM_Utils_System::redirect($tsysSettingsForm);
    }
  }

  public function buildQuickForm() {
    $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('all');

    $this->add('text', 'devicename', ts("Device Name"), [], TRUE);
    $this->add('text', 'ip', ts('IP address of Device'), [], TRUE);
    $this->add('text', 'terminalid', ts('Terminal ID for Device'), [], TRUE);
    $this->addEntityRef('processorid', ts('Payment Processor'), [
      'entity' => 'PaymentProcessor',
      'placeholder' => ts('- Select Payment Processor -'),
      'select' => ['minimumInputLength' => 0],
    ], TRUE);

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Save'),
        'isDefault' => TRUE,
      ),
    ));
    if ($this->_action) {
      if (!empty($_GET['id']) && $this->_action == CRM_Core_Action::UPDATE) {
        if (!empty($deviceSettings[$_GET['id']])) {
          // add previous id to the form
          $this->addElement('hidden', 'prev_id', $_GET['id']);
          $this->setDefaults($deviceSettings[$_GET['id']]);
        }
      }
    }
    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('all');
    $values = $this->exportValues();
    $deviceDetails = [];
    $fieldsToSave = [
      'devicename',
      'ip',
      'terminalid',
      'processorid',
    ];

    foreach ($fieldsToSave as $key => $fieldName) {
      if (!empty($values[$fieldName])) {
        $deviceDetails[$fieldName] = $values[$fieldName];
      }
    }
    $deviceSettings[$deviceDetails['terminalid']] = $deviceDetails;

    try {
      $tsysDevices = civicrm_api3('Setting', 'create', [
        'tsys_devices' => $deviceSettings,
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }
    if ($tsysDevices['is_error'] == 0) {
      CRM_Core_Session::setStatus(E::ts('Device %2 (%1) created successfully', array(
        1 => $deviceDetails['terminalid'],
        2 => $deviceDetails['devicename']
      )), SUCCESS, info);
      parent::postProcess();
      $tsysSettingsForm = CRM_Utils_System::url('civicrm/tsyssettings');
      CRM_Utils_System::redirect($tsysSettingsForm);
    }
    else {
      CRM_Core_Session::setStatus(E::ts('Device not created: %1', array(
        1 => $error,
      )));
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