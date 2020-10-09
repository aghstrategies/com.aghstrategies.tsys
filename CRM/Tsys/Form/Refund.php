<?php

use CRM_Tsys_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Tsys_Form_Refund extends CRM_Core_Form {
  // Form to submit a refund to TSYS

  /**
   * Set variables up before form is built.
   */
  public function preProcess() {
    $this->_action = CRM_Core_Action::UPDATE;
    parent::preProcess();
    $this->_id = CRM_Utils_Request::retrieve('id', 'Positive', $this);
    $this->assign('id', $this->_id);
    $this->_contributionID = CRM_Utils_Request::retrieve('contribution_id', 'Positive', $this);

    $this->_values = civicrm_api3('FinancialTrxn', 'getsingle', ['id' => $this->_id]);

    $tsysProcessors = CRM_Core_Payment_Tsys::getAllTsysPaymentProcessors();

    if (!empty($this->_values['payment_processor_id']) && !in_array($this->_values['payment_processor_id'], $tsysProcessors)) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($this->_values['payment_processor_id']);
      $actionInfo = CRM_Core_Payment_Tsys::determineAvailableActions($tsysCreds, $this->_values['trxn_id']);

      if (!empty($actionInfo['actionAvailable'])) {
        $this->actionAvailable = $actionInfo['actionAvailable'];
      }
      if (!empty($actionInfo['maxRefundAmount'])) {
        $this->maxRefundAmount = $actionInfo['maxRefundAmount'];
      }
    }
    // If the payment was not run thru Genius bounce
    else {
      CRM_Core_Error::statusBounce(E::ts('You cannot update this payment as it is not tied to a Genius payment processor'));
    }
  }

  public function buildQuickForm() {
    $defaults = [];

    // If the transaction has been batched and thus should be refunded
    if ($this->actionAvailable == 'Refund') {
      // Documentation on AddMoney => https://github.com/civicrm/civicrm-core/blob/3329ccb30f7dab40ed0f3aa85ff30dff6901c8da/CRM/Core/Form.php#L1906
      $this->addMoney(
        'refund_amount',
        E::ts('Amount to Refund'),
        // Required?
        TRUE,
        [],
        FALSE,
        'currency',
        NULL,
        FALSE
      );

      $this->addButtons(array(
        array(
          'type' => 'submit',
          'name' => E::ts('Issue Refund'),
          'isDefault' => TRUE,
        ),
      ));

      if (isset($this->maxRefundAmount)) {
        $this->add('hidden','max_refund_amount', $this->maxRefundAmount);
        $defaults['refund_amount'] = $this->maxRefundAmount|crmMoney;
        CRM_Core_Session::setStatus(E::ts('Amount Available to Refund for this payment is $%1. Submitting this form will result in a refund being issued from your Genius payment processor.', array(
          1 => $this->maxRefundAmount,
        )), '', 'no-popup');
      }
    }
    // If payment has not been batched and thus should be voided
    elseif ($this->actionAvailable == 'Void') {
      $this->addMoney(
        'refund_amount',
        E::ts('Amount to be Voided'),
        // Required?
        TRUE,
        ['readonly' => TRUE],
        FALSE,
        'currency',
        NULL,
        FALSE
      );

      $this->addButtons(array(
        array(
          'type' => 'submit',
          'name' => E::ts('Void Payment'),
          'isDefault' => TRUE,
        ),
      ));

      $this->add('hidden','payment_id', $this->_id);
      if (!empty($this->_values['total_amount'])) {
        $defaults['refund_amount'] = $this->_values['total_amount'];
        CRM_Core_Session::setStatus(E::ts('This transaction has not been settled and so it is recommended that you void the full amount $%1 instead of refunding it. Submitting this form will result in this transaction being voided via your Genius Payment processor.', array(
          1 => $this->_values['total_amount'],
        )), '', 'no-popup');
      }
    }
    else {
      CRM_Core_Session::setStatus(E::ts('No Credit Card Actions are available for this payment at this time.'), '', 'no-popup');
    }

    $this->add('hidden','og_fin_trxn', $this->_values['id']);
    $this->add('hidden','pan_truncation', $this->_values['pan_truncation']);
    $this->add('hidden','card_type_id', $this->_values['card_type_id']);
    $this->add('hidden','contribution_id', $this->_contributionID);
    $this->add('hidden','trxn_id', $this->_values['trxn_id']);
    $this->add('hidden','payment_processor_id', $this->_values['payment_processor_id']);
    $this->add('hidden','formaction', $this->actionAvailable);

    $this->setDefaults($defaults);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $values = $this->exportValues();
    // Get tsys credentials
    if (!empty($values['payment_processor_id'])
      && !empty($values['refund_amount'])
      && !empty($values['trxn_id']
      // && !empty($values['formaction'])
    )) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($values['payment_processor_id']);
      if (!empty($tsysCreds)) {

        // Create Payment
        try {
          $doRefund = civicrm_api3('PaymentProcessor', 'refund', [
            'currency' => 'USD',
            'trxn_id' => $values['trxn_id'],
            'amount' => $values['refund_amount'],
            'payment_processor_id' => $values['payment_processor_id'],
            'create_payment' => TRUE,
          ]);
        }
        catch (CiviCRM_API3_Exception $e) {
          $error = $e->getMessage();
          CRM_Core_Error::debug_log_message(E::ts('API Error %1', array(
            'domain' => 'com.aghstrategies.tsys',
            1 => $error,
          )));
          CRM_Core_Error::debug_var('Genius VOID/REFUND form values to be processed', $values);
          CRM_Core_Session::setStatus(E::ts('Refund Failed'), '', 'error');
        }
        if (empty($doRefund['error'])) {
          CRM_Core_Session::setStatus(E::ts('Refund issued'), '', 'success');
        }
      }
      else {
        CRM_Core_Session::setStatus(E::ts('No credentials for this processor in TSYS'), '', 'error');
      }
    }
    else {
      CRM_Core_Session::setStatus(E::ts('Missing data to process refund'), '', 'error');
      CRM_Core_Error::debug_var('Genius VOID/REFUND form values to be processed', $values);
    }
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
