<?php
/*
 * Payment Processor class for Recurring Tsys Transactions
 */
class CRM_Tsys_Recur {

  /**
   * @param $contribution an array of a contribution to be created (or in case of future start date,
   * possibly an existing pending contribution to recycle, if it already has a contribution id).
   * @param $options like is membership or send email receipt
   * @param $original_contribution_id if included, use as a template for a recurring contribution.
   *
   *   A high-level utility function for making a contribution payment from an existing recurring schedule
   *   Used in the Tsysrecurringcontributions.php job and the one-time ('card on file') form.
   *
   *   Since 4.7.12, we can are using the new repeattransaction api.
   *
   * Borrowed from https://github.com/iATSPayments/com.iatspayments.civicrm/blob/master/iats.php#L1285 _iats_process_contribution_payment
   */
  function processContributionPayment(&$contribution, $options, $original_contribution_id) {
    // By default, don't use repeattransaction:
    // Borrowed from https://github.com/iATSPayments/com.iatspayments.civicrm/blob/2bf9dcdb1537fb75649aa6304cdab991a8a9d1eb/iats.php#L1285
    $use_repeattransaction = FALSE;
    $is_recurrence = !empty($original_contribution_id);
    try {
      $paymentToken = civicrm_api3('ContributionRecur', 'getsingle', [
        'return' => ["payment_token_id.token"],
        'id' => $contribution['contribution_recur_id'],
      ]);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }
    if (!empty($paymentToken['payment_token_id.token'])) {
      $contribution['payment_token'] = $paymentToken['payment_token_id.token'];
    }
    $result = self::processTransaction($contribution, 'contribute');

    // Initialize the status to pending:
    $contribution['contribution_status_id'] = "Pending";

    // We processed it successflly and I can try to use repeattransaction.
    // Requires the original contribution id.
    // Issues with this api call:
    // 1. Always triggers an email and doesn't include trxn.
    // 2. Date is wrong.
    $use_repeattransaction = $is_recurrence && empty($contribution['id']);
    if ($use_repeattransaction) {
      // We processed it successflly and I can try to use repeattransaction.
      // Requires the original contribution id.
      // Issues with this api call:
      // 1. Always triggers an email and doesn't include trxn.
      // 2. Date is wrong.
      try {
        // $status = $result['contribution_status_id'] == 1 ? 'Completed' : 'Pending';
        $contributionResult = civicrm_api3('Contribution', 'repeattransaction', array(
          'original_contribution_id' => $original_contribution_id,
          'contribution_status_id' => 'Pending',
          'is_email_receipt' => 0,
          // 'invoice_id' => $contribution['invoice_id'],
          // 'receive_date' => $contribution['receive_date'],
          // 'campaign_id' => $contribution['campaign_id'],
          // 'financial_type_id' => $contribution['financial_type_id'],
          // 'payment_processor_id' => $contribution['payment_processor'],
          'contribution_recur_id' => $contribution['contribution_recur_id'],
        ));
        $contribution['id'] = CRM_Utils_Array::value('id', $contributionResult);
      }
      catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        CRM_Core_Error::debug_log_message(ts('API Error %1', array(
          'domain' => 'com.aghstrategies.tsys',
          1 => $error,
        )));
      }
      if (empty($contribution['id'])) {
        // Assume I failed completely and fall back to doing it the manual way.
        $use_repeattransaction = FALSE;
      }
      else {
        // If repeattransaction succeded.
        // First restore/add various fields that the repeattransaction api may
        // overwrite or ignore.
        try {
          civicrm_api3('contribution', 'create', array(
            'id' => $contribution['id'],
            'invoice_id' => $contribution['invoice_id'],
            'source' => $contribution['source'],
            'receive_date' => $contribution['receive_date'],
            'payment_instrument_id' => $contribution['payment_instrument_id'],
          ));
        }
        catch (CiviCRM_API3_Exception $e) {
          $error = $e->getMessage();
          CRM_Core_Error::debug_log_message(ts('API Error %1', array(
            'domain' => 'com.aghstrategies.tsys',
            1 => $error,
          )));
        }
        // Save my status in the contribution array that was passed in.
        $contribution['contribution_status_id'] = $result['contribution_status_id'];
        if ($result['contribution_status_id'] == 1) {
          // My transaction completed, so record that fact in CiviCRM,
          // potentially sending an invoice.
          try {
            civicrm_api3('Contribution', 'completetransaction', array(
              'id' => $contribution['id'],
              'payment_processor_id' => $contribution['payment_processor'],
              'is_email_receipt' => (empty($options['is_email_receipt']) ? 0 : 1),
              'trxn_id' => $result['payment_token'],
              'receive_date' => $contribution['receive_date'],
            ));
          }
          catch (CiviCRM_API3_Exception $e) {
            $error = $e->getMessage();
            CRM_Core_Error::debug_log_message(ts('API Error %1', array(
              'domain' => 'com.aghstrategies.tsys',
              1 => $error,
            )));
          }
        }
      }
    }
    if (!$use_repeattransaction) {
      // If I'm not using repeattransaction for any reason,
      // I'll create the contribution manually.
      // This code assumes that the contribution_status_id has been set
      // properly above, either pending or failed.
      $contributionResult = civicrm_api3('contribution', 'create', $contribution);
      // Pass back the created id indirectly since I'm calling by reference.
      $contribution['id'] = CRM_Utils_Array::value('id', $contributionResult);
      // Connect to a membership if requested.
      if (!empty($options['membership_id'])) {
        try {
          civicrm_api3('MembershipPayment', 'create', array('contribution_id' => $contribution['id'], 'membership_id' => $options['membership_id']));
        }
        catch (CiviCRM_API3_Exception $e) {
          $error = $e->getMessage();
          CRM_Core_Error::debug_log_message(ts('API Error %1', array(
            'domain' => 'com.aghstrategies.tsys',
            1 => $error,
          )));
        }
      }
      /* And then I'm done unless it completed */
      if ($result['contribution_status_id'] == 1 && !empty($result['status'])) {
        /* success, and the transaction has completed */
        $complete = array(
          'id' => $contribution['id'],
          'payment_processor_id' => $contribution['payment_processor'],
          'trxn_id' => $contribution['payment_token'],
          'receive_date' => $contribution['receive_date'],
        );
        $complete['is_email_receipt'] = empty($options['is_email_receipt']) ? 0 : 1;
        try {
          $contributionResult = civicrm_api3('contribution', 'completetransaction', $complete);
        }
        catch (CiviCRM_API3_Exception $e) {
          $error = $e->getMessage();
          $contribution['source'] .= ' [with unexpected api.completetransaction error: ' . $error . ']';
          CRM_Core_Error::debug_log_message(ts('API Error %1', array(
            'domain' => 'com.aghstrategies.tsys',
            1 => $error,
          )));
        }
        $message = $is_recurrence ? ts('Successfully processed contribution in recurring series id %1: ', array(1 => $contribution['contribution_recur_id'])) : ts('Successfully processed one-time contribution: ');
        return $message . $result['auth_result'];
      }
    }
    // Now return the appropriate message.
    if ($result['contribution_status_id'] == 1) {
      return ts('Successfully processed recurring contribution in series id %1: ', array(1 => $contribution['contribution_recur_id']));
    }
    else {
      return ts('Failed to process recurring contribution id %1: ', array(1 => $contribution['contribution_recur_id']));
    }
  }

  /**
   * @param  array $contribution the contribution
   * @param  array $options      options selected
   * @return array              the contribution
   * Borrowed from _iats_process_transaction https://github.com/iATSPayments/com.iatspayments.civicrm/blob/2bf9dcdb1537fb75649aa6304cdab991a8a9d1eb/iats.php#L1446
   */
  function processTransaction($contribution, $options) {
    // IF no Payment Token throw error.
    if (empty($contribution['payment_token']) || $contribution['payment_token'] == "Authorization token") {
      CRM_Core_Error::statusBounce(ts('Unable to complete payment! Please this to the site administrator with a description of what you were trying to do.'));
      Civi::log()->debug('Tsys token was not passed!  Report this message to the site administrator. $contribution: ' . print_r($contribution, TRUE));
    }

    // Get tsys credentials.
    if (!empty($contribution['payment_processor'])) {
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($contribution['payment_processor'], array(
        "signature",
        "subject",
        "user_name",
      ));
    }

    // Throw an error if no credentials found.
    if (empty($tsysCreds)) {
      CRM_Core_Error::statusBounce(ts('No valid payment processor credentials found'));
      Civi::log()->debug('No valid Tsys credentials found.  Report this message to the site administrator. $contribution: ' . print_r($contribution, TRUE));
    }
    // Make transaction
    $makeTransaction = CRM_Tsys_Soap::composeSaleSoapRequestToken(
      $contribution['payment_token'],
      $tsysCreds,
      $contribution['total_amount']
    );

    // If transaction approved.
    if (!empty($makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus) && $makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus == "APPROVED") {
      $contribution['trxn_id'] = $makeTransaction->Body->SaleResponse->SaleResult->Token;
      $contribution['trxn_result_code'] = $makeTransaction->Body->SaleResponse->SaleResult->AuthorizationCode;
      $contribution['pan_truncation'] = substr($makeTransaction->Body->SaleResponse->SaleResult->CardNumber, -4);

      $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
      $contribution['contribution_status_id'] = $completedStatusId;
      $query = "SELECT COUNT(token) FROM civicrm_payment_token WHERE token = %1";
      $queryParams = array(1 => array($contribution['payment_token'], 'String'));
      // If transaction is recurring AND there is not an existing vault token
      // saved.
      if (CRM_Utils_Array::value('is_recur', $contribution) && CRM_Core_DAO::singleValueQuery($query, $queryParams) == 0 && !empty($contribution['contribution_recur_id'])) {
        $paymentTokenId = CRM_Tsys_Recur::boardCard(
          $recur_id,
          $makeTransaction->Body->SaleResponse->SaleResult->Token,
          $tsysCreds,
          $contribution['contact_id'],
          $contribution['payment_processor']
        );
      }
      return $contribution;
    }
    // If transaction fails.
    else {
      // Record Failed Transaction
      $failedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
      $contribution['contribution_status_id'] = $failedStatusId;
      return $contribution;
    }
  }

  /**
   * For a recurring contribution, find a candidate for a template!
   */
  function getContributionTemplate($contribution) {
    // Get the 1st contribution in the series that matches the total_amount:
    $template = array();
    $get = array(
      'contribution_recur_id' => $contribution['contribution_recur_id'],
      'options' => array('sort' => ' id', 'limit' => 1),
    );
    if (!empty($contribution['total_amount'])) {
      $get['total_amount'] = $contribution['total_amount'];
    }
    $result = civicrm_api3('contribution', 'get', $get);
    if (!empty($result['values'])) {
      $contribution_ids = array_keys($result['values']);
      $template = $result['values'][$contribution_ids[0]];
      $template['original_contribution_id'] = $contribution_ids[0];
      $template['line_items'] = array();
      $get = array('entity_table' => 'civicrm_contribution', 'entity_id' => $contribution_ids[0]);
      $result = civicrm_api3('LineItem', 'get', $get);
      if (!empty($result['values'])) {
        foreach ($result['values'] as $initial_line_item) {
          $line_item = array();
          foreach (array(
            'price_field_id',
            'qty',
            'line_total',
            'unit_price',
            'label',
            'price_field_value_id',
            'financial_type_id',
          ) as $key) {
            $line_item[$key] = $initial_line_item[$key];
          }
          $template['line_items'][] = $line_item;
        }
      }
    }
    return $template;
  }

  function getInstallmentsDone($type = 'simple') {
    // Restrict this method of recurring contribution processing to only this payment processors.
    $args = array(
      1 => array('Payment_Tsys', 'String'),
    );

    if ($type == 'simple') {
      $select = 'SELECT cr.id, count(c.id) AS installments_done, cr.installments
        FROM civicrm_contribution_recur cr
          INNER JOIN civicrm_contribution c ON cr.id = c.contribution_recur_id
          INNER JOIN civicrm_payment_processor pp ON cr.payment_processor_id = pp.id
          LEFT JOIN civicrm_option_group og
            ON og.name = "contribution_status"
          LEFT JOIN civicrm_option_value rs
            ON cr.contribution_status_id = rs.value
            AND rs.option_group_id = og.id
          LEFT JOIN civicrm_option_value cs
            ON c.contribution_status_id = cs.value
            AND cs.option_group_id = og.id
        WHERE
          (pp.class_name = %1)
          AND (cr.installments > 0)
          AND (rs.name IN ("In Progress"))
          AND (cs.name IN ("Completed", "Pending"))
        GROUP BY c.contribution_recur_id';
    }
    elseif ($type == 'dates') {
      $select = 'SELECT cr.id, count(c.id) AS installments_done, cr.installments, cr.end_date, NOW() as test_now
          FROM civicrm_contribution_recur cr
          INNER JOIN civicrm_contribution c ON cr.id = c.contribution_recur_id
          INNER JOIN civicrm_payment_processor pp
            ON cr.payment_processor_id = pp.id
              AND pp.class_name = %1
          LEFT JOIN civicrm_option_group og
            ON og.name = "contribution_status"
          LEFT JOIN civicrm_option_value rs
            ON cr.contribution_status_id = rs.value
            AND rs.option_group_id = og.id
          LEFT JOIN civicrm_option_value cs
            ON c.contribution_status_id = cs.value
            AND cs.option_group_id = og.id
          WHERE
            (cr.installments > 0)
            AND (rs.name IN ("In Progress", "Completed"))
            AND (cs.name IN ("Completed", "Pending"))
          GROUP BY c.contribution_recur_id';
    }
    $dao = CRM_Core_DAO::executeQuery($select, $args);
    return $dao;
  }

  /**
   * This is a recurring donation, save the card for future use
   * @param  [type] $params    [description]
   * @param  [type] $token     [description]
   * @param  [type] $tsysCreds [description]
   * @return [type]            [description]
   */
  public static function boardCard($recur_id, $token, $tsysCreds, $contactId, $paymentProcessor) {
    $paymentTokenId = NULL;
    // Board Card (save card) with TSYS
    $boardCard = CRM_Tsys_Soap::composeBoardCardSoapRequest(
      $token,
      $tsysCreds
    );
    // IF card boarded successfully save the vault token to the database
    if (!empty($boardCard->Body->BoardCardResponse->BoardCardResult->VaultToken)) {
      $vaultToken = (string) $boardCard->Body->BoardCardResponse->BoardCardResult->VaultToken;
      // Save token in civi Database
      try {
        $paymentToken = civicrm_api3('PaymentToken', 'create', [
          'contact_id' => $contactId,
          'payment_processor_id' => $paymentProcessor,
          'token' => $vaultToken,
        ]);
      }
      catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        CRM_Core_Error::debug_log_message(ts('API Error %1', array(
          'domain' => 'com.aghstrategies.tsys',
          1 => $error,
        )));
      }
      if (!empty($paymentToken['id'])) {
        try {
          $result = civicrm_api3('ContributionRecur', 'create', [
            'id' => $recur_id,
            'payment_token_id' => $paymentToken['id'],
            'processor_id' => $paymentProcessor,
          ]);
        }
        catch (CiviCRM_API3_Exception $e) {
          $error = $e->getMessage();
          CRM_Core_Error::debug_log_message(ts('API Error %1', array(
            'domain' => 'com.aghstrategies.tsys',
            1 => $error,
          )));
        }
        $paymentTokenId = $paymentToken['id'];
      }
      if ($paymentToken['is_error'] == 1) {
        CRM_Core_Error::statusBounce(ts('Error saving payment token to database'));
      }
    }
    // If no vault token record Error
    else {
      CRM_Core_Error::statusBounce(ts('Card not saved for future use'));
      Civi::log()->debug('Credit Card not boarded to Tsys Error Message: ' . print_r($boardCard->Body->BoardCardResponse->BoardCardResult->ErrorMessage, TRUE));
    }
    return $paymentTokenId;
  }
}
