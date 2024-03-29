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

    // If the payment was run thru Genius check if it should be voided (has not been batched yet) or Refunded (has been batched)
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
          CRM_Core_Error::debug_var('Genius VOID/REFUND DetailedTransactionByReferenceResponse', $response);
        }
      }
      else {
        CRM_Core_Error::debug_var('Genius VOID/REFUND DetailedTransactionByReferenceResponse', $response);
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
        $defaults['refund_amount'] = $this->maxRefundAmount;
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
      && !empty($values['formaction']))
    ) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($values['payment_processor_id']);
      if (!empty($tsysCreds)) {
        if ($values['formaction'] == 'Refund') {
          $runRefund = CRM_Tsys_Soap::composeRefundCardSoapRequest($values['trxn_id'], $values['refund_amount'], $tsysCreds);
          $response = $runRefund->Body->RefundResponse->RefundResult;
          self::processResponse($response, $values);
        }
        elseif ($values['formaction'] == 'Void') {
          $voidResponse = CRM_Tsys_Soap::composeVoidSoapRequest($values['trxn_id'], $tsysCreds);
          $response = $voidResponse->Body->VoidResponse->VoidResult;
          self::processResponse($response, $values);
        }
      }
    }
    else {
      CRM_Core_Error::debug_var('Genius VOID/REFUND form values to be processed', $values);
    }
    parent::postProcess();
  }

  /**
   * Process Refund Response - Update user and payment/contribution status
   * @param  object $runRefund Response from Tsys
   * @param  array  $values    form values
   * @return
   */
  public function processResponse($response, $values) {
    // We got a legible response!!
    if (isset($response->ApprovalStatus)) {
      // Void successful in Genius so update CiviCRM payment accordingly
      if ((string) $response->ApprovalStatus == 'APPROVED') {
        $trxnParams['total_amount'] = -$values['refund_amount'];
        $trxnParams['payment_processor_id'] = $values['payment_processor_id'];
        $trxnParams['contribution_id'] = $values['contribution_id'];
        $trxnParams['card_type_id'] = $values['card_type_id'];
        $trxnParams['pan_truncation'] = $values['pan_truncation'];

        if (isset($response->Token)) {
          $trxnParams['trxn_id'] = (string) $response->Token;
        }
        if (isset($response->AuthorizationCode)) {
          $trxnParams['trxn_result_code'] = (string) $response->AuthorizationCode;
        }
        if (isset($response->TransactionDate)) {
          $trxnParams['trxn_date'] = (string) $response->TransactionDate;
        }

        $refund = self::createRefundInCivi($trxnParams, $values);

        // Update the user everything went well
        CRM_Core_Session::setStatus(
          E::ts('%1 of payment approved', [
            1 => $values['formaction']
          ]),
          E::ts('%1 Approved', [
            1 => $values['formaction']
          ]),
          'success'
        );
      }
      // Explicitly failed or declined so retrieve and throw error
      elseif (substr($response->ApprovalStatus, 0, 8) == "DECLINED" || substr($response->ApprovalStatus, 0, 6) == "FAILED") {
        $approvalStatus = explode(';', $response->ApprovalStatus);
        CRM_Core_Error::debug_var('Genius VOID/REFUND form values', $values);
        $errorTitle =   E::ts('%1 Failed', [
          1 => $values['formaction'],
        ]);
        if (count($approvalStatus) == 3) {
          $errorMessage =   E::ts('Error Code %1, %2', [
            1 => $approvalStatus[1],
            2 => $approvalStatus[2],
          ]);
        } else {
          $errorMessage = $approvalStatus;
        }
        if (isset($response->ErrorMessage)) {
          $errorMessage .= "; $response->ErrorMessage";
        }
        CRM_Core_Session::setStatus(
          $errorMessage,
          $errorTitle,
          'error'
        );
      }
      else {
        self::refundError($response, $values);
      }
    }
    // We did not get a legible response
    else {
      self::refundError($response, $values);
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

  public function refundError($response, $values) {
    CRM_Core_Session::setStatus(
      E::ts('%1 Response could not be found see logs for more details.', [
        1 => $values['formaction'],
      ]),
      E::ts('%1 Failed', [
        1 => $values['formaction'],
      ]),
      'error'
    );
    CRM_Core_Error::debug_var('Genius VOID/REFUND response', $response);
    CRM_Core_Error::debug_var('Genius VOID/REFUND form submit values', $values);
  }

  public function createRefundInCivi($trxnParams, $values) {

    // Create Payment
    try {
      $updateTrxnStatus = civicrm_api3('Payment', 'create', $trxnParams);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(E::ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }

    // BECAUSE payment.create does not create a Financial Item we need to create
    // a Financial Item so that the Financial Type shows up properly for more
    // details see: https://lab.civicrm.org/dev/financial/issues/87

    // Get Contribution Contact (because we need it to create the financial item)
    try {
      $contributionContact = civicrm_api3('Contribution', 'getvalue', [
        'return' => "contact_id",
        'id' => $trxnParams['contribution_id'],
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(E::ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }

    // Get Financial Type (because we need it to create the financial item)
    try {
      $eft = civicrm_api3('EntityFinancialTrxn', 'getsingle', [
        'financial_trxn_id' => $values['og_fin_trxn'],
        'entity_table' => "civicrm_financial_item",
        'api.FinancialItem.get' => ['id' => "\$value.entity_id"],
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(E::ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }

    try {
      $fi = civicrm_api3('FinancialItem', 'getsingle', [
        'id' => $eft['entity_id'],
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(E::ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }

    $finItemParams = [
      'entity_table' => "civicrm_financial_trxn",
      'transaction_date' => $updateTrxnStatus['values'][$updateTrxnStatus['id']]['trxn_date'],
      'entity_id' => $updateTrxnStatus['id'],
      'financial_account_id' => $fi['financial_account_id'],
      'status_id' => 1,
      'contact_id' => $contributionContact,
      'amount' => $updateTrxnStatus['values'][$updateTrxnStatus['id']]['total_amount'],
      'description' => "Genius Refund",
      'currency' => "USD",
    ];

    try {
      $createFinItem = civicrm_api3('FinancialItem', 'create', $finItemParams);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(E::ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }

    // And connect the Financial Item to the trxn using Entity Financial Trxn
    try {
      $entityFinTrxn = civicrm_api3('EntityFinancialTrxn', 'create', [
        'entity_table' => "civicrm_financial_item",
        'entity_id' => $createFinItem['id'],
        'financial_trxn_id' => $updateTrxnStatus['id'],
        'amount' => $updateTrxnStatus['values'][$updateTrxnStatus['id']]['total_amount'],
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(E::ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }
  }

}
