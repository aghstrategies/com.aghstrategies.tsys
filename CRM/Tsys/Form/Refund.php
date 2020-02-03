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


    $tsysProcessors = CRM_Core_Payment_Tsys::getAllTsysPaymentProcessors();
    if (!empty($this->_values['payment_processor_id']) && !in_array($this->_values['payment_processor_id'], $tsysProcessors)) {
      // TODO Check Refund Amount -- 'RefundMaxAmount' is showing up as 0 so this is not very useful --
      // unless these transactions need to be voided not refunded
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($this->_values['payment_processor_id']);
      $tsysInfo = CRM_Tsys_Soap::composeCheckBalanceSoapRequest($this->_values['trxn_id'], $tsysCreds);
      $response = $tsysInfo->Body->DetailedTransactionByReferenceResponse->DetailedTransactionByReferenceResult;
      if ((string) $response->ApprovalStatus == 'APPROVED') {
        if (isset($response->SupportedActions->RefundToken) && (string) $response->SupportedActions->RefundToken != '' && $response->SupportedActions->RefundMaxAmount > 0) {
          $this->actionAvailable = 'Refund';
          $this->maxRefundAmount = $response->SupportedActions->RefundMaxAmount;
        }
        if (isset($response->SupportedActions->VoidToken) && (string) $response->SupportedActions->VoidToken != '') {
          $this->actionAvailable = 'Void';
        }
      }
    }
    else {
      CRM_Core_Error::statusBounce(ts('You cannot update this payment as it is not tied to a TSYS payment processor'));
    }
  }

  public function buildQuickForm() {
    $defaults = [];
    // add form elements
    // Documentation on AddMoney => https://github.com/civicrm/civicrm-core/blob/3329ccb30f7dab40ed0f3aa85ff30dff6901c8da/CRM/Core/Form.php#L1906
    if ($this->actionAvailable == 'Refund') {
      $amountFieldName = E::ts('Amount to Refund');
      $buttonName = E::ts('Issue Refund');
      $extras = [];
      if (isset($this->maxRefundAmount)) {
        $this->add('hidden','max_refund_amount', $this->maxRefundAmount);
        $defaults['refund_amount'] = $this->maxRefundAmount|crmMoney;
        CRM_Core_Session::setStatus(E::ts('Amount Available to Refund: $%1. Submitting this form will result in a refund being issued from your TSYS payment procesor.', array(
          1 => $this->maxRefundAmount,
        )), '', 'no-popup');
      }
    }
    elseif ($this->actionAvailable == 'Void') {
      $amountFieldName = E::ts('Amount to be Voided');
      $buttonName = E::ts('Void Payment');
      $extras = ['readonly' => TRUE];
      if (!empty($this->_values['total_amount'])) {
        $defaults['refund_amount'] = $this->_values['total_amount'];
        CRM_Core_Session::setStatus(E::ts('This transaction has not been settled it is recommended you void the full amount $%1. Submitting this form will result in this transaction being voided via your TSYS payment procesor.', array(
          1 => $this->_values['total_amount'],
        )), '', 'no-popup');
      }
    }
    $this->addMoney(
      'refund_amount',
      $amountFieldName,
      // Required?
      TRUE,
      $extras,
      FALSE,
      'currency',
      NULL,
      FALSE
    );
    $this->add('hidden','contribution_id', $this->_contributionID);
    $this->add('hidden','trxn_id', $this->_values['trxn_id']);
    $this->add('hidden','payment_processor_id', $this->_values['payment_processor_id']);
    $this->add('hidden','formaction', $this->actionAvailable);

    $this->setDefaults($defaults);

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => $buttonName,
        'isDefault' => TRUE,
      ),
    ));

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    $values = $this->exportValues();
    print_r($values); die();
    // Get tsys credentials ($params come from a form)
    if (!empty($values['payment_processor_id'])
      && !empty($values['refund_amount'])
      && !empty($values['trxn_id']
      && !empty($values['formaction']))
    ) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($values['payment_processor_id']);

      if (!empty($tsysCreds)) {
        if ($values['formaction'] == 'Refund') {
          $runRefund = CRM_Tsys_Soap::composeRefundCardSoapRequest($values['trxn_id'], $values['refund_amount'], $tsysCreds);
          self::processRefundResponse($runRefund, $values);
        }
        elseif ($values['formaction'] == 'Void') {
          // TODO write code to void
          $voidResponse = CRM_Tsys_Soap::composeVoidSoapRequest($values['trxn_id'], $values['refund_amount'], $tsysCreds);
          self::processVoidResponse($voidResponse, $values);
        }

      }
    }
    parent::postProcess();
  }

  /**
   * Process Refund Response - Update user and payment/contribution status
   * @param  object $runRefund Response from Tsys
   * @param  array  $values    form values
   * @return
   */
  public function processRefundResponse($runRefund, $values) {
    $text = '';
    $title = '';
    $type = 'no-popup';
    // We got a legible response!!
    if (isset($runRefund->Body->RefundResponse->RefundResult->ApprovalStatus)) {
      // Refund processed successfully in TSYS so update CiviCRM payment accordingly
      if ($runRefund->Body->RefundResponse->RefundResult->ApprovalStatus == 'APPROVED') {
        // Record the Refund as a new payment of a negative amount
        $trxnParams = [
          // 'status_id' => "Refunded",
          'total_amount' => -$values['refund_amount'],
          'payment_processor_id' => $values['payment_processor_id'],
          'contribution_id' => $values['contribution_id'],
        ];
        if (isset($runRefund->Body->RefundResponse->RefundResult->TransactionDate)) {
          $trxnParams['trxn_date'] = (string) $runRefund->Body->RefundResponse->RefundResult->TransactionDate;
        }
        if (isset($runRefund->Body->RefundResponse->RefundResult->Token)) {
          $trxnParams['trxn_id'] = (string) $runRefund->Body->RefundResponse->RefundResult->Token;
        }
        if (isset($runRefund->Body->RefundResponse->RefundResult->AuthorizationCode)) {
          $trxnParams['trxn_result_code'] = (string) $runRefund->Body->RefundResponse->RefundResult->AuthorizationCode;
        }
        try {
          $updateTrxnStatus = civicrm_api3('Payment', 'create', $trxnParams);
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
