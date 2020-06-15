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

   $this->addButtons([[
    'type' => 'cancel',
    'name' => E::ts('Cancel'),
   ]]);

    // Set up cancel transaction while in progress
    $res = CRM_Core_Resources::singleton();
    // TODO is there a javascript way to compile this url?
    $transportUrl = CRM_Utils_System::url('civicrm/tsys/transportkey', NULL, TRUE, NULL, FALSE, FALSE, FALSE);
    $processUrl = CRM_Utils_System::url('civicrm/processdeviceresponse', NULL, TRUE, NULL, FALSE, FALSE, FALSE);

    $res->addVars('tsys', [
      'ips' => $deviceSettings,
      'transport' => $transportUrl,
      'process' => $processUrl,
    ]);

    // TODO these can probably be combined
    $res->addScriptFile('com.aghstrategies.tsys', 'js/cancelDevice.js');
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
