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
