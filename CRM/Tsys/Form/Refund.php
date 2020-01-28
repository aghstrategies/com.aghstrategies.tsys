<?php

use CRM_Tsys_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Tsys_Form_Refund extends CRM_Core_Form {

  /**
   * Set variables up before form is built.
   */
  public function preProcess() {
    // $this->_action = CRM_Core_Action::UPDATE;
    parent::preProcess();
    $this->_id = CRM_Utils_Request::retrieve('id', 'Positive', $this);
    $this->assign('id', $this->_id);
    $this->_contributionID = CRM_Utils_Request::retrieve('contribution_id', 'Positive', $this);

    $this->_values = civicrm_api3('FinancialTrxn', 'getsingle', ['id' => $this->_id]);
    if (!empty($this->_values['payment_processor_id'])) {
      // TODO throw error if payment is tied to a payment processor that cannot be refunded (not TSYS)
      // CRM_Core_Error::statusBounce(ts('You cannot update this payment as it is tied to a payment processor'));
    }
  }

  public function buildQuickForm() {
    $defaults = [];

    // add form elements
    $this->addMoney(
      'refund_amount',
      E::ts('Amount to Refund'),
      TRUE,
      ['readonly' => TRUE],
      TRUE,
      'currency',
      NULL,
      TRUE
    );

    if (!empty($this->_values['total_amount'])) {
      $defaults['refund_amount'] = $this->_values['total_amount'];
    }

    $this->setDefaults($defaults);

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Issue Refund'),
        'isDefault' => TRUE,
      ),
    ));

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $values = $this->exportValues();
    CRM_Core_Session::setStatus(E::ts('Refund issued', array(
      1 => 'success',
    )));
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
