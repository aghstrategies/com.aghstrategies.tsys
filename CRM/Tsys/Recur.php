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
   *   Used in the Tsysrecurringcontributions.php job
   *
   *
   * Borrowed from https://github.com/iATSPayments/com.iatspayments.civicrm/blob/master/iats.php#L1285 _iats_process_contribution_payment
   */
  public static function processContributionPayment(&$contribution, $options, $original_contribution_id) {
    // Get Contribution Statuses
    $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
    $failedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');

    // Get Vault Token
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
    // Save the payment token to the contribution
    if (!empty($paymentToken['payment_token_id.token'])) {
      $token = $paymentToken['payment_token_id.token'];
    }
    // IF no payment token throw an error and quit
    else {
      // CRM_Core_Error::statusBounce(ts('Unable to complete payment! Please this to the site administrator with a description of what you were trying to do.'));
      Civi::log()->debug('Tsys token was not passed!  Report this message to the site administrator. $contribution: ' . print_r($contribution, TRUE));
      return ts('no payment token found for recurring contribution in series id %1: ', array(1 => $contribution['contribution_recur_id']));
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
      return ts('no Tsys Credentials found for payment processor id: %1 ', array(1 => $contribution['payment_processor']));
    }

    // Use the payment token to make the transaction
    self::processRecurTransaction($contribution, $token, 'contribute', $tsysCreds, $completedStatusId, $failedStatusId);
    if (empty($contribution['contribution_status_id'])) {
      $contribution['contribution_status_id'] = $failedStatusId;
    }
    // This code assumes that the contribution_status_id has been set properly above, either completed or failed.
    try {
      $contributionResult = civicrm_api3('contribution', 'create', $contribution);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }
    // Pass back the created id indirectly since I'm calling by reference.
    $contribution['id'] = CRM_Utils_Array::value('id', $contributionResult);
    // Connect to a membership if requested.
    if (!empty($options['membership_id'])) {
      try {
       $membershipPayment = civicrm_api3('MembershipPayment', 'create', array(
         'contribution_id' => $contribution['id'],
         'membership_id' => $options['membership_id']
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
    // if contribution needs to be completed
    if ($contribution['contribution_status_id'] == $completedStatusId) {
      $complete = array(
       'id' => $contribution['id'],
       'payment_processor_id' => $contribution['payment_processor'],
       'trxn_id' => $contribution['trxn_id'],
       'receive_date' => $contribution['receive_date'],
      );
      $complete['is_email_receipt'] = empty($options['is_email_receipt']) ? 0 : 1;
      try {
       $contributionResult = civicrm_api3('contribution', 'completetransaction', $complete);
      }
      catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        CRM_Core_Error::debug_log_message(ts('API Error %1', array(
          'domain' => 'com.aghstrategies.tsys',
          1 => $error,
        )));
      }
    }
    // Now return the appropriate message.
    if ($contribution['contribution_status_id'] == $completedStatusId && $contributionResult['is_error'] == 0) {
      return ts('Successfully processed recurring contribution in series id %1: ', array(1 => $contribution['contribution_recur_id']));
    }
    else {
      return ts('Failed to process recurring contribution id %1: ', array(1 => $contribution['contribution_recur_id']));
    }
  }

  /**
   * @param  array $contribution the contribution
   * @param  array $options      options selected
   * @param  array $tsysCreds    payment processor credentials
   * @return array               the contribution
   *
   * Borrowed from _iats_process_transaction
   * https://github.com/iATSPayments/com.iatspayments.civicrm/blob/2bf9dcdb1537fb75649aa6304cdab991a8a9d1eb/iats.php#L1446
   *
   */
  public static function processRecurTransaction(&$contribution, $token, $options, $tsysCreds, $completedStatusId, $failedStatusId) {
    // Make transaction
    $makeTransaction = CRM_Tsys_Soap::composeSaleSoapRequestToken(
      $token,
      $tsysCreds,
      $contribution['total_amount'],
      rand(1, 1000000)
    );

    // add relevant information from the tsys response
    $contribution = CRM_Core_Payment_Tsys::processResponseFromTsys($contribution, $makeTransaction);

    // If transaction approved.
    if (!empty($makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus) && $makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus == "APPROVED") {

      // Update the status to completed
      $contribution['contribution_status_id'] = $completedStatusId;
      return;
    }
    // If transaction fails.
    else {
      if (!empty($contribution['approval_status'])) {
        Civi::log()->debug('Credit Card not processed - Tsys Approval Status: ' . print_r($contribution['approval_status'], TRUE));
      }
      if (!empty($contribution['error_message'])) {
        Civi::log()->debug('Credit Card not processed - Tsys Error Message: ' . print_r($contribution['error_message'], TRUE));
      }
      // Record Failed Transaction
      $contribution['contribution_status_id'] = $failedStatusId;
      return;
    }
  }

  /**
   * For a recurring contribution, find a candidate for a template!
   * @param  array $contribution   Contribution Details
   * @return array $template       Template for the recurring contribution
   */
  public static function getContributionTemplate($contribution) {
    // Get the 1st contribution in the series that matches the total_amount:
    $template = array();
    $get = array(
      'contribution_recur_id' => $contribution['contribution_recur_id'],
      'options' => array('sort' => ' id', 'limit' => 1),
      'return' => ['tax_amount', 'contribution_recur_id', 'total_amount', 'id', 'campaign_id', 'amount_level'],
    );
    if (!empty($contribution['total_amount'])) {
      $get['total_amount'] = $contribution['total_amount'];
    }
    try {
      $ogContribution = civicrm_api3('contribution', 'get', $get);
    }
    catch (CiviCRM_API3_Exception $e) {
      $error = $e->getMessage();
      CRM_Core_Error::debug_log_message(ts('API Error %1', array(
        'domain' => 'com.aghstrategies.tsys',
        1 => $error,
      )));
    }
    if (!empty($ogContribution['values'])) {
      $contribution_ids = array_keys($ogContribution['values']);
      $template = $ogContribution['values'][$contribution_ids[0]];
      $template['original_contribution_id'] = $contribution_ids[0];
      if ($ogContribution['values'][$contribution_ids[0]]['tax_amount']) {
        $template['tax_amount'] = $ogContribution['values'][$contribution_ids[0]]['tax_amount'];
      }
      $template['line_items'] = array();
      $get = array('entity_table' => 'civicrm_contribution', 'entity_id' => $contribution_ids[0]);
      try {
        $lineItem = civicrm_api3('LineItem', 'get', $get);
      }
      catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        CRM_Core_Error::debug_log_message(ts('API Error %1', array(
          'domain' => 'com.aghstrategies.tsys',
          1 => $error,
        )));
      }
      if (!empty($lineItem['values'])) {
        foreach ($lineItem['values'] as $initial_line_item) {
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

  /**
   * Database Queries to get how many Installments are done and how many are left
   * @param  string $type simple or dates -- determines which query to use
   * @return object $dao  result from database
   */
  public static function getInstallmentsDone($type = 'simple') {
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
   * @param  int   $recur_id         recurring contribution id
   * @param  int   $token            previous transaction token from first contribution in the series
   * @param  array $tsysCreds        Tsys Credentials
   * @param  int   $contactId        Contact ID of contributor
   * @param  int   $paymentProcessor Payment Processor id
   * @return int   $paymentTokenId   ID of the payment token now saved to civicrm_payment_token
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
          $addPaymentToken = civicrm_api3('ContributionRecur', 'create', [
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
      // CRM_Core_Error::statusBounce(ts('Card not saved for future use'));
      Civi::log()->debug('Credit Card not boarded to Tsys Error Message: ' . print_r($boardCard->Body->BoardCardResponse->BoardCardResult->ErrorMessage, TRUE));
    }
    return $paymentTokenId;
  }
}
