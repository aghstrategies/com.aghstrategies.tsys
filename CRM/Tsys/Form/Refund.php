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

    // If the payment was run thru TSYS check if it should be voided (has not been batched yet) or Refunded (has been batched)
    $tsysProcessors = CRM_Core_Payment_Tsys::getAllTsysPaymentProcessors();
    if (!empty($this->_values['payment_processor_id']) && !in_array($this->_values['payment_processor_id'], $tsysProcessors)) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($this->_values['payment_processor_id']);
      $tsysInfo = CRM_Tsys_Soap::composeCheckBalanceSoapRequest($this->_values['trxn_id'], $tsysCreds);
      $response = $tsysInfo->Body->DetailedTransactionByReferenceResponse->DetailedTransactionByReferenceResult;
      if ((string) $response->ApprovalStatus == 'APPROVED') {
        if (isset($response->SupportedActions->RefundToken) && (string) $response->SupportedActions->RefundToken != '' && $response->SupportedActions->RefundMaxAmount > 0) {
          $this->actionAvailable = 'Refund';
          $this->maxRefundAmount = $response->SupportedActions->RefundMaxAmount;
        }
        elseif (isset($response->SupportedActions->VoidToken) && (string) $response->SupportedActions->VoidToken != '') {
          $this->actionAvailable = 'Void';
        }
        else {
          $this->actionAvailable = 'None';
        }
      }
    }
    // If the payment was not run thru TSYS bounce
    else {
      CRM_Core_Error::statusBounce(ts('You cannot update this payment as it is not tied to a TSYS payment processor'));
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
        CRM_Core_Session::setStatus(E::ts('Amount Available to Refund for this payment is $%1. Submitting this form will result in a refund being issued from your TSYS payment processor.', array(
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
        CRM_Core_Session::setStatus(E::ts('This transaction has not been settled and so it is recommended that you void the full amount $%1 instead of refunding it. Submitting this form will result in this transaction being voided via your TSYS Payment processor.', array(
          1 => $this->_values['total_amount'],
        )), '', 'no-popup');
      }
    }
    else {
      CRM_Core_Session::setStatus(E::ts('No Credit Card Actions are available for this payment at this time.'), '', 'no-popup');
    }

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
      && !empty($values['formaction']))
    ) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($values['payment_processor_id']);
      if (!empty($tsysCreds)) {
        if ($values['formaction'] == 'Refund') {
          $runRefund = CRM_Tsys_Soap::composeRefundCardSoapRequest($values['trxn_id'], $values['refund_amount'], $tsysCreds);
          self::processRefundResponse($runRefund, $values);
        }
        elseif ($values['formaction'] == 'Void') {
          $voidResponse = CRM_Tsys_Soap::composeVoidSoapRequest($values['trxn_id'], $tsysCreds);
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
        CRM_Core_Session::setStatus(
          'Payment successfully refunded.',
          E::ts('Refund Approved'),
          'success'
        );
      }
      // Refund failed explicitly so retrieve error
      elseif (substr($runRefund->Body->RefundResponse->RefundResult->ApprovalStatus, 0, 6 ) == "FAILED") {
        $approvalStatus = explode(';', $runRefund->Body->RefundResponse->RefundResult->ApprovalStatus);
        if (count($approvalStatus) == 3) {
          CRM_Core_Session::setStatus(
            E::ts('Error Code %1, %2', array(
              1 => $approvalStatus[1],
              2 => $approvalStatus[2],
            )),
            E::ts('Refund Failed'),
            'error'
          );
        } else {
          CRM_Core_Session::setStatus(
            $approvalStatus,
            E::ts('Refund Failed'),
            'error'
          );
        }
      }
    }

    // We did not get a legible response
    else {
      CRM_Core_Session::setStatus(
        E::ts('Refund Response could not be found see logs for more details.'),
        E::ts('Refund Failed'),
        'error'
      );
      // TODO add debug logs
    }
  }

  /**
   * Process Refund Response - Update user and payment/contribution status
   * @param  object $runRefund Response from Tsys
   * @param  array  $values    form values
   * @return
   */
  public function processVoidResponse($voidResponse, $values) {
    $response = $voidResponse->Body->VoidResponse->VoidResult;
    // We got a legible response!!
    if (isset($response->ApprovalStatus)) {
      // Void successful in TSYS so update CiviCRM payment accordingly
      if ((string) $response->ApprovalStatus == 'APPROVED') {
        // Record the Refund as a new payment of a negative amount
        $trxnParams = [
          'status_id' => "Cancelled",
          'id' => $values['payment_id'],
        ];
        if (isset($response->TransactionDate)) {
          $cancelDate = (string) $response->TransactionDate;
        }
        if (isset($response->Token)) {
          $trxnParams['trxn_id'] = (string) $response->Token;
        }
        if (isset($response->AuthorizationCode)) {
          $trxnParams['trxn_result_code'] = (string) $response->AuthorizationCode;
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
        // TODO update the contribution status
        // IF the contribution consists of one payment and that payment is this one set contrib status to Cancelled
        // IF the contribution consists of multiple payments and this payment is just one of them set the status to "Partially Paid"
        self::calculateContributionStatus($values['refund_amount'], $values['contribution_id'], $cancelDate);

        // Update the user everything went well
        CRM_Core_Session::setStatus(
          'Payment successfully refunded.',
          E::ts('Refund Approved'),
          'success'
        );
      }
      // Refund failed explicitly so retrieve error
      elseif (substr($response->ApprovalStatus, 0, 6 ) == "FAILED") {
        $approvalStatus = explode(';', $response->ApprovalStatus);
        if (count($approvalStatus) == 3) {
          CRM_Core_Session::setStatus(
            E::ts('Error Code %1, %2', array(
              1 => $approvalStatus[1],
              2 => $approvalStatus[2],
            )),
            E::ts('Void Failed'),
            'error'
          );
        } else {
          CRM_Core_Session::setStatus(
            $approvalStatus,
            E::ts('Void Failed'),
            'error'
          );
        }
      }
    }
    // We did not get a legible response
    else {
      CRM_Core_Session::setStatus(
        E::ts('Void Response could not be found see logs for more details.'),
        E::ts('Void Failed'),
        'error'
      );
      // TODO add debug logs
    }
  }

  /**
   * Payment has been voided update the contribution status
   */
  public function calculateContributionStatus($voidedAmount, $contributionId, $cancelDate) {
    try {
      $contribution = civicrm_api3('Contribution', 'getsingle', ['id' => $contributionId]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }
    if (isset($contribution['contribution_status'])) {
      switch ($contribution['contribution_status']) {
        case 'Partially paid':
          // Stay Partially paid AKA do nothing
          $status = 'Partially paid';
          break;

        case 'Completed':
          // If the contribution was completed and we just voided the one
          // payment that completed it we should mark the contribution as cancelled
          if ($contribution['total_amount'] == $voidedAmount) {
            self::updateContributionStatus([
              'id' => $contributionId,
              'contribution_status_id' => 'Cancelled',
              // 'cancel_date' => $cancelDate,
            ]);

          // IF the payment we voided is NOT the full amount we should mark the contribution as partially paid
          // TODO make it possible for contributions to go from "Completed" -> "Partially Paid" using
          // the API https://lab.civicrm.org/dev/core/issues/1574
          } else {
            self::updateContributionStatus([
              'id' => $contributionId,
              'contribution_status_id' => 'Partially paid',
            ]);
          }
          break;

        // TODO I think we would need to do Adjustment to be able to go from Pending refund to
        // Completed using Void
        case 'Pending refund':
          break;

        default:
          // code...
          break;
      }
    }
  }

  public function updateContributionStatus($params) {
    try {
      $contribution = civicrm_api3('Contribution', 'create', $params);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
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
