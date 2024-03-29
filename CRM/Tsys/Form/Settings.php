<?php

use CRM_Tsys_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Tsys_Form_Settings extends CRM_Core_Form {
  // Settings form for setting up TSYS devices. Other TSYS settings could be
  // added here later down the line.

  /**
   * The action links that we need to display for the browse screen.
   *
   * @var array
   */
  public static $_links = NULL;

  public function preProcess() {
    $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('all');
    if ($_GET['action'] == 'test' && !empty($_GET['id']) && !empty($deviceSettings[$_GET['id']])) {
      $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('all');
      $res = CRM_Core_Resources::singleton();
      $res->addVars('tsys', [
        'ip' => $deviceSettings[$_GET['id']]['ip'],
        'id' => $_GET['id'],
      ]);
      $res->addScriptFile('com.aghstrategies.tsys', 'js/testDevice.js');
    }
  }

  public function buildQuickForm() {

    $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('all');
    foreach ($deviceSettings as $key => &$details) {
      $details['id'] = $key;
      $details['action'] = CRM_Core_Action::formLink(self::links($details['is_enabled']), NULL,
        ['id' => $key],
        E::ts('more'),
        FALSE,
        'tsysdevice.manage.action',
        'TsysDevice',
        $key
      );
    }
    $this->assign('devices', $deviceSettings);

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ],
    ]);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $values = $this->exportValues();
    parent::postProcess();
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
    $elementNames = [];
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

  /**
  * Get action Links.
  *
  * @return array
  *   (reference) of action links
  */
 public function &links($enabled) {
   if (!(self::$_links)) {
     self::$_links = [
       CRM_Core_Action::UPDATE => [
         'name' => E::ts('Edit'),
         'url' => 'civicrm/tsyssettings/device',
         'qs' => 'action=update&id=%%id%%&reset=1',
         'title' => E::ts('Edit Genius Device'),
       ],
       CRM_Core_Action::DELETE => [
         'name' => E::ts('Delete'),
         'url' => 'civicrm/tsyssettings/device',
         'qs' => 'action=delete&id=%%id%%',
         'title' => E::ts('Delete Genius Device'),
      ],
      100 => [
        'name' => E::ts('Test'),
        'url' => 'civicrm/tsyssettings',
        'qs' => 'action=test&id=%%id%%',
        'title' => E::ts('Test Genius Device'),
      ],
     ];
     if ($enabled == 0) {
       self::$_links[CRM_Core_Action::ENABLE] = [
         'name' => E::ts('Enable'),
         'url' => 'civicrm/tsyssettings/device',
         'qs' => 'action=enable&id=%%id%%',
         'title' => ts('Enable this Device'),
       ];
     }
     else {
       self::$_links[CRM_Core_Action::DISABLE] = [
         'name' => E::ts('Disable'),
         'url' => 'civicrm/tsyssettings/device',
         'qs' => 'action=disable&id=%%id%%',
         'title' => ts('Disable this Device'),
       ];
     }
   }
   return self::$_links;
 }

}
