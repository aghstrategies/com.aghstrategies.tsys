<?php

use CRM_Tsys_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

/**
 * FIXME - Add test description.
 *
 * Tips:
 *  - With HookInterface, you may implement CiviCRM hooks directly in the test class.
 *    Simply create corresponding functions (e.g. "hook_civicrm_post(...)" or similar).
 *  - With TransactionalInterface, any data changes made by setUp() or test****() functions will
 *    rollback automatically -- as long as you don't manipulate schema or truncate tables.
 *    If this test needs to manipulate schema or truncate tables, then either:
 *       a. Do all that using setupHeadless() and Civi\Test.
 *       b. Disable TransactionalInterface, and handle all setup/teardown yourself.
 *
 * @group headless
 */
class CRM_Tsys_BaseTest extends \PHPUnit_Framework_TestCase implements HeadlessInterface, HookInterface, TransactionalInterface {

  protected $_contributionID;
  protected $_invoiceID = 'in_19WvbKAwDouDdbFCkOnSwAN7';
  protected $_financialTypeID = 1;
  protected $org;
  protected $_orgID;
  protected $contact;
  protected $_contactID;
  protected $_contributionPageID;
  protected $_paymentProcessorID;
  protected $_paymentProcessor;
  protected $_trxn_id;
  protected $_created_ts;
  protected $_subscriptionID;
  protected $_membershipTypeID;

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://github.com/civicrm/org.civicrm.testapalooza/blob/master/civi-test.md
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
    $this->createPaymentProcessor();
    $this->createContact();
    $this->createContributionPage();
    $this->_created_ts = time();
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Create contact.
   */
  function createContact() {
    if (!empty($this->_contactID)) {
      return;
    }
    $results = civicrm_api3('Contact', 'create', array(
      'contact_type' => 'Individual',
      'first_name' => 'Jose',
      'last_name' => 'Lopez'
    ));;
    $this->_contactID = $results['id'];
    $this->contact = (Object) array_pop($results['values']);

    // Now we have to add an email address.
    $email = 'susie@example.org';
    civicrm_api3('email', 'create', array(
      'contact_id' => $this->_contactID,
      'email' => $email,
      'location_type_id' => 1
    ));
    $this->contact->email = $email;
  }

  /**
   * Create a tsys payment processor.
   *
   */
  function createPaymentProcessor($params = array()) {
    // Get the payment processor type id
    $pptId = civicrm_api3('PaymentProcessorType', 'getsingle', [
      'return' => ["id"],
      'name' => "Tsys",
    ]);

    if (!empty($pptId['id'])) {
      $params = array(
    		'name' => 'Tsys Payment Processor',
    		'domain_id' => CRM_Core_Config::domainID(),
    		'payment_processor_type_id' => $pptId['id'],
    		'is_active' => 1,
    		'is_default' => 0,
    		'is_test' => 1,
    		'is_recur' => 1,
    		'url_site' => 'https://cayan.accessaccountdetails.com/',
    		'url_recur' => 'https://cayan.accessaccountdetails.com/',
    		'class_name' => 'Payment_Tsys',
    		'billing_mode' => 1
    	);

      // To test one must send the following environment variables
      $credentials = array(
        'user_name',
    		'password',
        'subject',
        'signature',
      );
      foreach ($credentials as $key => $credential) {
        if (getenv($credential)) {
          $params[$credential] = getenv($credential);
        }
        else {
          $this->fail("no {$credential} environment variable passed.");
         }
      }

      // First see if it already exists.
      $tsysPaymentProcessor = civicrm_api3('PaymentProcessor', 'get', $params);
      if ($tsysPaymentProcessor['count'] != 1) {
        // Nope, create it.
        $tsysPaymentProcessor = civicrm_api3('PaymentProcessor', 'create', $params);
      }
      // return civicrm_api3_create_success($tsysPaymentProcessor['values']);
      $processor = array_pop($tsysPaymentProcessor['values']);
      $this->_paymentProcessor = $processor;
      $this->_paymentProcessorID = $tsysPaymentProcessor['id'];
    }
  }

  /**
   * Create a tsys contribution page.
   *
   */
  function createContributionPage($params = array()) {
    $params = array_merge(array(
      'title' => "Test Contribution Page",
      'financial_type_id' => $this->_financialTypeID,
      'currency' => 'USD',
      'payment_processor' => $this->_paymentProcessorID,
      'max_amount' => 1000,
      'receipt_from_email' => 'gaia@the.cosmos',
      'receipt_from_name' => 'Pachamama',
      'is_email_receipt' => FALSE,
      ), $params);
    $result = civicrm_api3('ContributionPage', 'create', $params);
    $this->assertEquals(0, $result['is_error']);
    $this->_contributionPageID = $result['id'];
  }

  /**
   * Submit to tsys
   */
  public function doPayment($params = array()) {
    $mode = 'test';
    $pp = $this->_paymentProcessor;
    $tsys = new CRM_Core_Payment_Tsys($mode, $pp);
    $params = array_merge(array(
      'payment_processor_id' => $this->_paymentProcessorID,
      'amount' => 1.01,
      'cvv2' => '123',
      'credit_card_exp_date' => array(
        'M' => '09',
        'Y' => '2022',
      ),
      'location_type_id' => 5,
      'billing_street_address-5' => '555 north',
      'billing_postal_code-5' => '12324',
      'billing_first_name' => 'first',
      'billing_last_name' => 'last',
      'credit_card_number' => '4012000033330026',
      'email' => $this->contact->email,
      'contactID' => $this->contact->id,
      'description' => 'Test from tsys Test Code',
      'currencyID' => 'USD',
      'invoiceID' => $this->_invoiceID,
    ), $params);

    $ret = $tsys->doPayment($params);
    $completedStatusId = CRM_Core_PseudoConstant::getKey('CRM_Contribute_BAO_Contribution', 'contribution_status_id', 'Completed');

    $this->assertEquals($ret['payment_status_id'], $completedStatusId);
  }

  /**
   * Create contribition
   */
  public function setupTransaction($params = array()) {
     $contribution = civicrm_api3('contribution', 'create', array_merge(array(
      'contact_id' => $this->_contactID,
      'contribution_status_id' => 2,
      'payment_processor_id' => $this->_paymentProcessorID,
      // processor provided ID - use contact ID as proxy.
      'processor_id' => $this->_contactID,
      'total_amount' => 1.01,
      'invoice_id' => $this->_invoiceID,
      'financial_type_id' => $this->_financialTypeID,
      'contribution_status_id' => 'Pending',
      'contact_id' => $this->_contactID,
      'contribution_page_id' => $this->_contributionPageID,
      'payment_processor_id' => $this->_paymentProcessorID,
      'is_test' => 1,
    ), $params));
    $this->assertEquals(0, $contribution['is_error']);
    $this->_contributionID = $contribution['id'];
  }

  public function createOrganization() {
    if (!empty($this->_orgID)) {
      return;
    }
    $results = civicrm_api3('Contact', 'create', array(
      'contact_type' => 'Organization',
      'organization_name' => 'My Great Group'
    ));;
    $this->_orgID = $results['id'];
  }

  public function createMembershipType() {
    CRM_Member_PseudoConstant::flush('membershipType');
    CRM_Core_Config::clearDBCache();
    $this->createOrganization();
    $params = array(
      'name' => 'General',
      'duration_unit' => 'year',
      'duration_interval' => 1,
      'period_type' => 'rolling',
      'member_of_contact_id' => $this->_orgID,
      'domain_id' => 1,
      'financial_type_id' => 2,
      'is_active' => 1,
      'sequential' => 1,
      'visibility' => 'Public',
    );

    $result = civicrm_api3('MembershipType', 'Create', $params);

    $this->_membershipTypeID = $result['id'];

    CRM_Member_PseudoConstant::flush('membershipType');
    CRM_Utils_Cache::singleton()->flush();
  }

}
