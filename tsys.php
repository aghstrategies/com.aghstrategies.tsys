<?php

require_once 'tsys.civix.php';
use CRM_Tsys_ExtensionUtil as E;


function tsys_civicrm_links($op, $objectName, $objectId, &$links, &$mask, &$values) {
  // Adds a refund link to each payment made thru TSYS with a status of completed
  // TODO once PR https://github.com/civicrm/civicrm-core/pull/16401 has been accepted make this version of tsys require that version of CiviCRM
  if ($objectName == 'Payment' && $op == 'payment.edit.action') {
    if (!empty($values['contribution_id'])) {

      // DO NOT show refund link for payments that have failed or already been refunded.
      try {
        $contribDetails = civicrm_api3('Contribution', 'getsingle', [
          'id' => $values['contribution_id'],
        ]);
      }
      catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        CRM_Core_Error::debug_log_message(ts('API Error %1', array(
          'domain' => 'com.aghstrategies.tsys',
          1 => $error,
        )));
      }
      if (!empty($contribDetails['contribution_status']) && in_array($contribDetails['contribution_status'], ['Completed', 'Partially Paid', 'Pending refund'])) {
        try {
          $trxnDetails = civicrm_api3('FinancialTrxn', 'getsingle', [
            'return' => "payment_processor_id, status_id, trxn_id",
            'is_payment' => 1,
            'id' => $values['id'],
          ]);
        }
        catch (CiviCRM_API3_Exception $e) {
          $error = $e->getMessage();
          CRM_Core_Error::debug_log_message(ts('API Error %1', array(
            'domain' => 'com.aghstrategies.tsys',
            1 => $error,
          )));
        }
        $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');
        if (!empty($trxnDetails['status_id']) && $trxnDetails['status_id'] == $completedStatusId) {
          $tsysProcessors = CRM_Core_Payment_Tsys::getAllTsysPaymentProcessors();
          if ($trxnDetails['payment_processor_id'] && !empty($tsysProcessors[$trxnDetails['payment_processor_id']])) {
            $links[] = [
              'name' => 'Credit Card Actions',
              'url' => 'civicrm/tsys/refund',
              'class' => 'medium-popup',
              'qs' => 'reset=1&id=%%id%%&contribution_id=%%contribution_id%%',
              'title' => 'Credit Card Actions',
              'bit' => 2,
            ];
          }
        }
      }
    }
  }
}

/**
 * Implements hook_civicrm_buildForm().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_buildForm
 */
function tsys_civicrm_buildForm($formName, &$form) {
  // Load stripe.js on all civi forms per stripe requirements
  if (!isset(\Civi::$statics[E::LONG_NAME]['tsysJSLoaded'])) {
    \Civi::resources()->addScriptUrl('https://ecommerce.merchantware.net/v1/CayanCheckoutPlus.js');
    \Civi::$statics[E::LONG_NAME]['tsysJSLoaded'] = TRUE;
  }

  // If on a form with a Tsys Payment Processor
  if (!empty($form->_paymentProcessor['api.payment_processor_type.getsingle']['name'])
    && $form->_paymentProcessor['api.payment_processor_type.getsingle']['name'] == 'TSYS') {
    // Add data-cayan attributes to credit card fields so CayanCheckoutPlus script can find them:
    $form->updateElementAttr('credit_card_number', array('data-cayan' => 'cardnumber'));
    $form->updateElementAttr('cvv2', array('data-cayan' => 'cvv'));

    // Don't use \Civi::resources()->addScriptFile etc as they often don't work on AJAX loaded forms (eg. participant backend registration)
    \Civi::resources()->addVars('tsys', [
      'allApiKeys' => CRM_Core_Payment_Tsys::getAllTsysPaymentProcessors(),
      'pp' => CRM_Utils_Array::value('id', $form->_paymentProcessor),
    ]);
    CRM_Core_Region::instance('billing-block')->add([
      'scriptUrl' => \Civi::resources()->getUrl(E::LONG_NAME, "js/civicrm_tsys.js"),
    ]);
  }

  // Add help text
  if ($formName == 'CRM_Admin_Form_PaymentProcessor') {
    $templatePath = realpath(dirname(__FILE__) . "/templates");
    CRM_Core_Region::instance('form-buttons')->add(array(
      'template' => "{$templatePath}/tsys.tpl",
    ));
  }

}

/**
 * Implements hook_civicrm_validateForm().
 *
 * Prevent server validation of cc fields:
 */
function tsys_civicrm_validateForm($formName, &$fields, &$files, &$form, &$errors) {
  // This is copied from stripe: https://lab.civicrm.org/extensions/stripe/blob/master/stripe.php#L125
  // Ensures credit card number does not get sent to server in edge case
  if (empty($form->_paymentProcessor['payment_processor_type'])) {
    return;
  }
  // If Tsys is active here.
  if ($form->_paymentProcessor['class_name'] == 'Payment_Tsys') {
    if (isset($form->_elementIndex['payment_token'])) {
      if ($form->elementExists('credit_card_number')) {
        $cc_field = $form->getElement('credit_card_number');
        $form->removeElement('credit_card_number', TRUE);
        $form->addElement($cc_field);
      }
      if ($form->elementExists('cvv2')) {
        $cvv2_field = $form->getElement('cvv2');
        $form->removeElement('cvv2', TRUE);
        $form->addElement($cvv2_field);
      }
    }
  }
  else {
    return;
  }
}

/**
 * Implementation of hook_civicrm_check().
 */
function tsys_civicrm_check(&$messages) {
  // First get the TSYS Processors on this site
  try {
    $tsysProcesors = civicrm_api3('PaymentProcessor', 'get', [
      'sequential' => 1,
      'payment_processor_type_id' => "TSYS",
      'is_test' => 0,
    ]);
  }
  catch (CiviCRM_API3_Exception $e) {
    $error = $e->getMessage();
    CRM_Core_Error::debug_log_message(ts('API Error %1', array(
      'domain' => 'com.aghstrategies.tsys',
      1 => $error,
    )));
  }
  // If one or more TSYS payment processors are set up
  if (!empty($tsysProcesors['values'])) {
    $processors = [];
    foreach ($tsysProcesors['values'] as $key => $processorDets) {
      $processors[] = $processorDets['id'];
    }
    if (!empty($processors)) {
      // This adds a System Status message if their are Recurring Contributions that are not processing as expected.
      try {
        $failedContributions = civicrm_api3('ContributionRecur', 'get', [
          'sequential' => 1,
          'contribution_status_id' => ['IN' => ["Failed", "Pending"]],
          'payment_processor_id' => ['IN' => $processors],
        ]);
      }
      catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        CRM_Core_Error::debug_log_message(ts('API Error %1', array(
          'domain' => 'com.aghstrategies.tsys',
          1 => $error,
        )));
      }
      if (!empty($failedContributions['values']) && $failedContributions['count'] > 0) {
        $recurContributionToLookInto = [];
        foreach ($failedContributions['values'] as $key => $value) {
          $recurContributionToLookInto[] = $value['id'];
        }
        $recurContributionToLookInto = implode(', ', $recurContributionToLookInto);
        $warningLevel = \Psr\Log\LogLevel::NOTICE;
        if ($failedContributions['count'] > 3) {
          $warningLevel = \Psr\Log\LogLevel::WARNING;
        }
        if ($failedContributions['count'] > 5) {
          $warningLevel = \Psr\Log\LogLevel::ERROR;
        }
        $tsParams = array(
          1 => $failedContributions['count'],
          2 => $recurContributionToLookInto,
        );
        $details = ts('%1 Recurring Contribution(s) not successfully processed including the following recurring contribution(s): %2. <br></br> For more information run a "Recurring Contributions" report and filter for "Contribution Status" of "Pending"', $tsParams);
        $messages[] = new CRM_Utils_Check_Message(
          'failed_recurring_contributions_found',
          $details,
          ts('Uncompleted Recurring TSYS Contributions Found', array('domain' => 'com.aghstrategies.tsys')),
          $warningLevel,
          'fa-user-times'
        );
      }
    }
  }
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
  // Creates the payment processor entity for the Tsys Payment Processor
  $entities[] = array(
    'module' => 'com.aghstrategies.tsys',
    'name' => 'TSYS',
    'entity' => 'PaymentProcessorType',
    'params' => array(
      'version' => 3,
      'name' => 'TSYS',
      'title' => 'TSYS',
      'description' => 'TSYS Payment Processor',
      'class_name' => 'Payment_Tsys',
      'billing_mode' => 'form',
      'user_name_label' => 'Merchant Name',
      'password_label' => 'Web API Key',
      'signature_label' => 'Merchant Site Key',
      'subject_label' => 'Merchant Site ID',
      'url_site_default' => 'https://cayan.accessaccountdetails.com/',
      'url_recur_default' => 'https://cayan.accessaccountdetails.com/',
      'url_site_test_default' => 'https://cayan.accessaccountdetails.com/',
      'url_recur_test_default' => 'https://cayan.accessaccountdetails.com/',
      'is_recur' => 1,
      'payment_type' => 1,
    ),
    'metadata' => array(
      'suppress_submit_button' => 1,
      'payment_fields' => ['payment_token'],
    ),
  );
  return _tsys_civix_civicrm_managed($entities);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link http://wiki.civicrm.org/confluence/display/CRMDOC/hook_civicrm_install
 */
function tsys_civicrm_install() {
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
