<?php

use CRM_Tsys_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Tsys_Form_Settings extends CRM_Core_Form {

  /**
   * The action links that we need to display for the browse screen.
   *
   * @var array
   */
  public static $_links = NULL;

  public function buildQuickForm() {

    $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('all');
    foreach ($deviceSettings as $key => &$details) {
      $details['id'] = $key;
      $details['action'] = CRM_Core_Action::formLink(self::links(), NULL,
        array('id' => $key),
        ts('more'),
        FALSE,
        'tsysdevice.manage.action',
        'TsysDevice',
        $key
      );

    }
    $this->assign('devices', $deviceSettings);

    // add form elements
    $this->add(
      'select', // field type
      'favorite_color', // field name
      'Favorite Color', // field label
      $this->getColorOptions(), // list of options
      TRUE // is required
    );
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
    $values = $this->exportValues();
    $options = $this->getColorOptions();
    CRM_Core_Session::setStatus(E::ts('You picked color "%1"', array(
      1 => $options[$values['favorite_color']],
    )));
    parent::postProcess();
  }

  public function getColorOptions() {
    $options = array(
      '' => E::ts('- select -'),
      '#f00' => E::ts('Red'),
      '#0f0' => E::ts('Green'),
      '#00f' => E::ts('Blue'),
      '#f0f' => E::ts('Purple'),
    );
    foreach (array('1', '2', '3', '4', '5', '6', '7', '8', '9', 'a', 'b', 'c', 'd', 'e') as $f) {
      $options["#{$f}{$f}{$f}"] = E::ts('Grey (%1)', array(1 => $f));
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

  /**
  * Get action Links.
  *
  * @return array
  *   (reference) of action links
  */
 public function &links() {
   if (!(self::$_links)) {
     self::$_links = array(
       CRM_Core_Action::UPDATE => array(
         'name' => ts('Edit'),
         'url' => 'civicrm/tsyssettings/device',
         'qs' => 'action=update&id=%%id%%&reset=1',
         'title' => ts('Edit TSYS Device'),
       ),
       CRM_Core_Action::DELETE => array(
         'name' => ts('Delete'),
         'url' => 'civicrm/tsyssettings/device',
         'qs' => 'action=delete&id=%%id%%',
         'title' => ts('Delete TSYS Device'),
       ),
     );
   }
   return self::$_links;
 }

}
