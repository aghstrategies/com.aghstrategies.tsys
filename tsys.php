<?php

require_once 'tsys.civix.php';
use CRM_Tsys_ExtensionUtil as E;

/**
 * Implements hook_civicrm_buildForm().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_buildForm
 */
function tsys_civicrm_buildForm($formName, &$form) {
  if (!empty($form->_paymentProcessor) && $form->_paymentProcessor['api.payment_processor_type.getsingle']['name'] == 'Tsys') {
    $paymentProcessorId = CRM_Utils_Array::value('id', $form->_paymentProcessor);

    // The backend credit card registration form does not build the payment form the same as the rest of the credit card forms so we need to send this special
    if ($formName == 'CRM_Event_Form_Participant') {
      // Get API Key and provide it to JS
      $publishableKey = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($paymentProcessorId, "password");
      $publishableKey = $publishableKey['password'];
      CRM_Core_Resources::singleton()->addVars('tsys', array('api' => $publishableKey));
    }

    // Add data-cayan attributes to credit card fields
    $form->updateElementAttr('credit_card_number', array('data-cayan' => 'cardnumber'));
    $form->updateElementAttr('cvv2', array('data-cayan' => 'cvv'));

    // TODO use getPaymentFieldMetadata() to make year and month their own form fields

    // credit_card_exp_date is one form element but Tsys expects the month and year to be their own form elements using js to accomplish this
    CRM_Core_Resources::singleton()->addScriptFile('com.aghstrategies.tsys', 'js/civicrm_tsys.js', 'html-header');

    // TODO do we want to copy this file (as I have for now) or link to it?
    //  adding a local copy of https://ecommerce.merchantware.net/v1/CayanCheckoutPlus.js
    CRM_Core_Resources::singleton()->addScriptFile('com.aghstrategies.tsys', 'js/CayanCheckoutPlus.js', 'html-header');
  }
}

/**
 * For a recurring contribution, find a reasonable candidate for a template, where possible.
 */
function tsys_civicrm_getContributionTemplate($contribution) {
  // Get the first contribution in this series that matches the same total_amount, if present.
  $template = array();
  $get = array('contribution_recur_id' => $contribution['contribution_recur_id'], 'options' => array('sort' => ' id', 'limit' => 1));
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
        foreach (array('price_field_id', 'qty', 'line_total', 'unit_price', 'label', 'price_field_value_id', 'financial_type_id') as $key) {
          $line_item[$key] = $initial_line_item[$key];
        }
        $template['line_items'][] = $line_item;
      }
    }
  }
  return $template;
}

/**
 * Function _iats_contribution_payment
 *
 * @param $contribution an array of a contribution to be created (or in case of future start date,
          possibly an existing pending contribution to recycle, if it already has a contribution id).
 * @param $options must include customer code, subtype and iats_domain, may include a membership id
 * @param $original_contribution_id if included, use as a template for a recurring contribution.
 *
 *   A high-level utility function for making a contribution payment from an existing recurring schedule
 *   Used in the Iatsrecurringcontributions.php job and the one-time ('card on file') form.
 *
 *   Since 4.7.12, we can are using the new repeattransaction api.
 */
function _tsys_process_contribution_payment(&$contribution, $options, $original_contribution_id) {
  // By default, don't use repeattransaction
  $use_repeattransaction = FALSE;
  $is_recurrence = !empty($original_contribution_id);
  // First try and get the money with iATS Payments, using my cover function.
  // TODO: convert this into an api job?
  $contribution['payment_token'] = CRM_Core_DAO::singleValueQuery("SELECT vault_token FROM civicrm_tsys_recur WHERE recur_id = " . $contribution['contribution_recur_id']);
  $result = _tsys_process_transaction($contribution, 'contribute');

  // Initialize the status to pending
  $contribution['contribution_status_id'] = 2;

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
        ///'receive_date' => $contribution['receive_date'],
        // 'campaign_id' => $contribution['campaign_id'],
        // 'financial_type_id' => $contribution['financial_type_id'],.
        // 'payment_processor_id' => $contribution['payment_processor'],
        'contribution_recur_id' => $contribution['contribution_recur_id'],
      ));
      // watchdog('iats_civicrm','repeat transaction result <pre>@params</pre>',array('@params' => print_r($pending,TRUE)));.
      $contribution['id'] = CRM_Utils_Array::value('id', $contributionResult);
    }
    catch (Exception $e) {
      // Ignore this, though perhaps I should log it.
    }
    if (empty($contribution['id'])) {
      // Assume I failed completely and I'll fall back to doing it the manual way.
      $use_repeattransaction = FALSE;
    }
    else {
      // If repeattransaction succeded.
      // First restore/add various fields that the repeattransaction api may overwrite or ignore.
      // TODO - fix this in core to allow these to be set above.
      civicrm_api3('contribution', 'create', array('id' => $contribution['id'],
        'invoice_id' => $contribution['invoice_id'],
        'source' => $contribution['source'],
        'receive_date' => $contribution['receive_date'],
        'payment_instrument_id' => $contribution['payment_instrument_id'],
        // '' => $contribution['receive_date'],
      ));
      // Save my status in the contribution array that was passed in.
      $contribution['contribution_status_id'] = $result['contribution_status_id'];
      if ($result['contribution_status_id'] == 1) {
        // My transaction completed, so record that fact in CiviCRM, potentially sending an invoice.
        try {
          civicrm_api3('Contribution', 'completetransaction', array(
            'id' => $contribution['id'],
            'payment_processor_id' => $contribution['payment_processor'],
            'is_email_receipt' => (empty($options['is_email_receipt']) ? 0 : 1),
            'trxn_id' => $result['trxn_id'],
            'receive_date' => $contribution['receive_date'],
          ));
        }
        catch (Exception $e) {
          // log the error and continue
          CRM_Core_Error::debug_var('Unexpected Exception', $e);
        }
      }
      // else {
      //   // just save my trxn_id for ACH/EFT verification later
      //   try {
      //     civicrm_api3('Contribution', 'create', array(
      //       'id' => $contribution['id'],
      //       'trxn_id' => $contribution['trxn_id'],
      //     ));
      //   }
      //   catch (Exception $e) {
      //     // log the error and continue
      //     CRM_Core_Error::debug_var('Unexpected Exception', $e);
      //   }
      // }
    }
  }
  if (!$use_repeattransaction) {
    /* If I'm not using repeattransaction for any reason, I'll create the contribution manually */
    // This code assumes that the contribution_status_id has been set properly above, either pending or failed.
    $contributionResult = civicrm_api3('contribution', 'create', $contribution);
    // Pass back the created id indirectly since I'm calling by reference.
    $contribution['id'] = CRM_Utils_Array::value('id', $contributionResult);
    // Connect to a membership if requested.
    if (!empty($options['membership_id'])) {
      try {
        civicrm_api3('MembershipPayment', 'create', array('contribution_id' => $contribution['id'], 'membership_id' => $options['membership_id']));
      }
      catch (Exception $e) {
        // Ignore.
      }
    }
    /* And then I'm done unless it completed */
    if ($result['contribution_status_id'] == 1 && !empty($result['status'])) {
      /* success, and the transaction has completed */
      $complete = array('id' => $contribution['id'],
        'payment_processor_id' => $contribution['payment_processor'],
        'trxn_id' => $trxn_id,
        'receive_date' => $contribution['receive_date']
      );
      $complete['is_email_receipt'] = empty($options['is_email_receipt']) ? 0 : 1;
      try {
        $contributionResult = civicrm_api3('contribution', 'completetransaction', $complete);
      }
      catch (Exception $e) {
        // Don't throw an exception here, or else I won't have updated my next contribution date for example.
        $contribution['source'] .= ' [with unexpected api.completetransaction error: ' . $e->getMessage() . ']';
      }
      // Restore my source field that ipn code irritatingly overwrites, and make sure that the trxn_id is set also.
      civicrm_api3('contribution', 'setvalue', array('id' => $contribution['id'], 'value' => $contribution['source'], 'field' => 'source'));
      civicrm_api3('contribution', 'setvalue', array('id' => $contribution['id'], 'value' => $trxn_id, 'field' => 'trxn_id'));
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

function _tsys_process_transaction($contribution, $options) {
  // TODO generate a better trxn_id
  // cannot use invoice id in civi because it needs to be less than 8 numbers and all numeric.
  if (empty($contribution['trxn_id'])) {
    $contribution['trxn_id'] = rand(1, 1000000);
  }

  // IF no Payment Token throw error
  if (empty($contribution['payment_token']) || $contribution['payment_token'] == "Authorization token") {
    CRM_Core_Error::statusBounce(ts('Unable to complete payment! Please this to the site administrator with a description of what you were trying to do.'));
    Civi::log()->debug('Tsys token was not passed!  Report this message to the site administrator. $contribution: ' . print_r($contribution, TRUE));
  }

  // Get tsys credentials
  if (!empty($contribution['payment_processor'])) {
    $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($contribution['payment_processor'], array("signature", "subject", "user_name"));
  }

  // Throw an error if no credentials found
  if (empty($tsysCreds)) {
    CRM_Core_Error::statusBounce(ts('No valid payment processor credentials found'));
    Civi::log()->debug('No valid Tsys credentials found.  Report this message to the site administrator. $contribution: ' . print_r($contribution, TRUE));
  }
    // Make transaction
    // TODO decide if we need these params
    // $contribution['fee_amount'] = $stripeBalanceTransaction->fee / 100;
    // $contribution['net_amount'] = $stripeBalanceTransaction->net / 100;
    $makeTransaction = CRM_Core_Payment_Tsys::composeSaleSoapRequest(
      $contribution['payment_token'],
      $tsysCreds,
      $contribution['total_amount'],
      $contribution['trxn_id']
    );

    // If transaction approved
    if (!empty($makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus) && $makeTransaction->Body->SaleResponse->SaleResult->ApprovalStatus  == "APPROVED") {
      $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
      $contribution['contribution_status_id'] = $completedStatusId;
      $query = "SELECT COUNT(vault_token) FROM civicrm_tsys_recur WHERE vault_token = %1";
      $queryParams = array(1 => array($contribution['payment_token'], 'String'));
      // If transaction is recurring AND there is not an existing vault token saved
      if (CRM_Utils_Array::value('is_recur', $contribution) && CRM_Core_DAO::singleValueQuery($query, $queryParams) == 0 && !empty($contribution['contribution_recur_id'])) {
        CRM_Core_Payment_Tsys::boardCard($recur_id, $makeTransaction->Body->SaleResponse->SaleResult->Token, $tsysCreds);
      }
      return $contribution;
    }
    // If transaction fails
    else {
      $failedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Failed');
      $contribution['contribution_status_id'] = $failedStatusId;
      return $contribution;
    }
}

/**
 * Implements hook_civicrm_config().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_config
 */
function tsys_civicrm_config(&$config) {
  _tsys_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_xmlMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_xmlMenu
 */
function tsys_civicrm_xmlMenu(&$files) {
  _tsys_civix_civicrm_xmlMenu($files);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function tsys_civicrm_install() {
  $sql = "CREATE TABLE `civicrm_tsys_recur` (
    `id` int unsigned NOT NULL AUTO_INCREMENT  COMMENT 'Id',
    `vault_token` varchar(100) NOT NULL COMMENT 'Vault Token returned from TSYS',
    `recur_id` int(10) unsigned DEFAULT '0' COMMENT 'CiviCRM recurring_contribution table id',
    `identifier` varchar(255) DEFAULT 'CARD last 4' COMMENT 'Not used currently could be used to store identifying info for card',
    PRIMARY KEY ( `id` ),
    KEY (`recur_id`)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci COMMENT='Table to store vault tokens'";
  $createTable = CRM_Core_DAO::executeQuery($sql);
  _tsys_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_postInstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_postInstall
 */
function tsys_civicrm_postInstall() {
  _tsys_civix_civicrm_postInstall();
}

/**
 * Implements hook_civicrm_uninstall().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_uninstall
 */
function tsys_civicrm_uninstall() {
  _tsys_civix_civicrm_uninstall();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_enable
 */
function tsys_civicrm_enable() {
  _tsys_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_disable().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_disable
 */
function tsys_civicrm_disable() {
  _tsys_civix_civicrm_disable();
}

/**
 * Implements hook_civicrm_upgrade().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_upgrade
 */
function tsys_civicrm_upgrade($op, CRM_Queue_Queue $queue = NULL) {
  return _tsys_civix_civicrm_upgrade($op, $queue);
}

/**
 * Implements hook_civicrm_managed().
 *
 * Generate a list of entities to create/deactivate/delete when this module
 * is installed, disabled, uninstalled.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_managed
 */
function tsys_civicrm_managed(&$entities) {
  // TODO right now we use existing fields (subject_label and signature_label) I think we should make our own fields that are tsys specific
  $entities[] = array(
    'module' => 'com.aghstrategies.tsys',
    'name' => 'Tsys',
    'entity' => 'PaymentProcessorType',
    'params' => array(
      'version' => 3,
      'name' => 'Tsys',
      'title' => 'Tsys',
      'description' => 'Tsys Payment Processor',
      'class_name' => 'Payment_Tsys',
      'billing_mode' => 'form',
      'user_name_label' => 'Merchant Name',
      'password_label' => 'Web API Key',
      'signature_label' => 'Merchant Key',
      'subject_label' => 'Merchant Site',
      'url_site_default' => 'https://cayan.accessaccountdetails.com/',
      'url_recur_default' => 'https://cayan.accessaccountdetails.com/',
      'url_site_test_default' => 'https://cayan.accessaccountdetails.com/',
      'url_recur_test_default' => 'https://cayan.accessaccountdetails.com/',
      'is_recur' => 1,
      'payment_type' => 1
    ),
    'metadata' => array(
     'suppress_submit_button' => 1,
     'payment_fields' => ['payment_token'],
   ),
  );
  return _tsys_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_caseTypes().
 *
 * Generate a list of case-types.
 *
 * Note: This hook only runs in CiviCRM 4.4+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_caseTypes
 */
function tsys_civicrm_caseTypes(&$caseTypes) {
  _tsys_civix_civicrm_caseTypes($caseTypes);
}

/**
 * Implements hook_civicrm_angularModules().
 *
 * Generate a list of Angular modules.
 *
 * Note: This hook only runs in CiviCRM 4.5+. It may
 * use features only available in v4.6+.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_angularModules
 */
function tsys_civicrm_angularModules(&$angularModules) {
  _tsys_civix_civicrm_angularModules($angularModules);
}

/**
 * Implements hook_civicrm_alterSettingsFolders().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_alterSettingsFolders
 */
function tsys_civicrm_alterSettingsFolders(&$metaDataFolders = NULL) {
  _tsys_civix_civicrm_alterSettingsFolders($metaDataFolders);
}

/**
 * Implements hook_civicrm_entityTypes().
 *
 * Declare entity types provided by this module.
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_entityTypes
 */
function tsys_civicrm_entityTypes(&$entityTypes) {
  _tsys_civix_civicrm_entityTypes($entityTypes);
}

// --- Functions below this ship commented out. Uncomment as required. ---

/**
 * Implements hook_civicrm_preProcess().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_preProcess
 *
function tsys_civicrm_preProcess($formName, &$form) {

} // */

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_navigationMenu
 *
function tsys_civicrm_navigationMenu(&$menu) {
  _tsys_civix_insert_navigation_menu($menu, 'Mailings', array(
    'label' => E::ts('New subliminal message'),
    'name' => 'mailing_subliminal_message',
    'url' => 'civicrm/mailing/subliminal',
    'permission' => 'access CiviMail',
    'operator' => 'OR',
    'separator' => 0,
  ));
  _tsys_civix_navigationMenu($menu);
} // */
