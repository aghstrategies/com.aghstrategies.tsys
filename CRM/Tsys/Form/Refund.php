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
    $this->_action = CRM_Core_Action::UPDATE;
    parent::preProcess();
    $this->_id = CRM_Utils_Request::retrieve('id', 'Positive', $this);
    $this->assign('id', $this->_id);
    $this->_contributionID = CRM_Utils_Request::retrieve('contribution_id', 'Positive', $this);

    $this->_values = civicrm_api3('FinancialTrxn', 'getsingle', ['id' => $this->_id]);
    if (!empty($this->_values['payment_processor_id'])) {
      // TODO throw error if payment is tied to a payment processor that cannot be refunded (not TSYS), has already been refunded is not a payment etc.
      // CRM_Core_Error::statusBounce(ts('You cannot update this payment as it is tied to a payment processor'));
    }
  }

  public function buildQuickForm() {
    $defaults = [];

    // TODO should the amount to be refunded be editable?
    // add form elements
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
    $this->add('hidden','contribution_id', $this->_contributionID);
    $this->add('hidden','trxn_id', $this->_values['trxn_id']);
    $this->add('hidden','payment_processor_id', $this->_values['payment_processor_id']);
    $this->add('hidden','payment_id', $this->_id);

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

  //TODO test this works for recurring payments the same way
  public function postProcess() {
    $values = $this->exportValues();
    // Get tsys credentials ($params come from a form)
    if (!empty($values['payment_processor_id'])
      && !empty($values['refund_amount'])
      && !empty($values['trxn_id'])
      && !empty($values['payment_id'])
    ) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($values['payment_processor_id']);

      if (!empty($tsysCreds)) {
        $runRefund = CRM_Tsys_Soap::composeRefundCardSoapRequest($values['trxn_id'], $values['refund_amount'], $tsysCreds);
        self::processRefundResponse($runRefund, $values);
      }
    }
    parent::postProcess();
  }

  public function processRefundResponse($runRefund, $values) {
    // TODO deal with scenario where a user is issuing a partial refund
    // TODO deal with scenario where user paid $100 and then switches to a $75 option and needs to be refunded the difference
    $text = '';
    $title = '';
    $type = 'no-popup';
    // We got a legible response!!
    if (isset($runRefund->Body->RefundResponse->RefundResult->ApprovalStatus)) {
      // Refund processed successfully in TSYS so update CiviCRM payment accordingly
      if ($runRefund->Body->RefundResponse->RefundResult->ApprovalStatus == 'APPROVED') {
        // TODO write function to update the contribution status for refunded payment... this needs some thinking thru
        // Update the payment status to refunded
        $trxnParams = [
          'id' => $values['payment_id'],
          'status_id' => "Refunded",
        ];
        if (isset($runRefund->Body->RefundResponse->RefundResult->TransactionDate)) {
          $trxnParams['trxn_date'] = $runRefund->Body->RefundResponse->RefundResult->TransactionDate;
        }
        try {
          $updateTrxnStatus = civicrm_api3('FinancialTrxn', 'create', $trxnParams);
        }
        catch (CiviCRM_API3_Exception $e) {
          $error = $e->getMessage();
          CRM_Core_Error::debug_log_message(ts('API Error %1', array(
            'domain' => 'com.aghstrategies.tsys',
            1 => $error,
          )));
        }

        // Update the user everything went well
        $text = 'Payment successfully refunded.';
        $title = 'Refund Approved';
        $type = 'success';
      }
      // Refund failed explicitly so retrieve error
      elseif (substr($runRefund->Body->RefundResponse->RefundResult->ApprovalStatus, 0, 6 ) == "FAILED") {
        $title = E::ts('Refund Failed');
        $approvalStatus = explode(';', $runRefund->Body->RefundResponse->RefundResult->ApprovalStatus);
        if (count($approvalStatus) == 3) {
          $text = E::ts('Error Code %1, %2', array(
            1 => $approvalStatus[1],
            2 => $approvalStatus[2],
          ));
        } else {
          $text = $approvalStatus;
        }
      }
    }
    // We did not get a legible response
    else {
      $title = E::ts('Refund Failed');
      $text = E::ts('Refund Response could not be found see logs for more details.');
      // TODO add debug logs
    }
    CRM_Core_Session::setStatus($text, $title, $type);
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
