<?php

use CRM_Tsys_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

require ('BaseTest.php');
class CRM_Tsys_OneTimeContributionTsysTest extends CRM_Tsys_BaseTest {
  // Tests of one time tsys transactions


  protected $_contributionRecurID;
  protected $_total = '200';

  public function setUpHeadless() {
    // Civi\Test has many helpers, like install(), uninstall(), sql(), and sqlFile().
    // See: https://docs.civicrm.org/dev/en/latest/testing/phpunit/#civitest
    return \Civi\Test::headless()
      ->installMe(__DIR__)
      ->apply();
  }

  public function setUp() {
    parent::setUp();
  }

  public function tearDown() {
    parent::tearDown();
  }

  /**
   * Genius Checkout 1.00 C
   * Sale Vault: Process a Sale transaction using the single-use token received.
   */
  public function testCayanCertificationScriptGeniusCheckout1C() {
    $this->setupTransaction();
    $params = [
      'amount' => 1.01,
      'credit_card_number' => '4012000033330026',
    ];
    $results = $this->doPayment($params, 'live');
    $this->assertEquals($results['trxn_result_code'], 'SAL101');
    $this->assertEquals($results['payment_status_id'], $this->_completedStatusID);
    $this->spitOutResults('Genius Checkout 1.00 C', $results);
  }

  /**
   * Genius Checkout 7.00 C
   * Sale Vault Decline Response
   */
  public function testCayanCertificationScriptGeniusCheckout7C() {
    $this->setupTransaction();
    $params = [
      'amount' => 3.01,
      'credit_card_number' => '4012000033330026',
    ];
    $results = $this->doPayment($params, 'live');

    $this->assertEquals($results['payment_status_id'], $this->_failedStatusID);
    $this->assertEquals($results['approval_status'], 'DECLINED;1012;decline');
    $this->spitOutResults('Genius Checkout 7.00 C', $results);
  }

  /**
   * Genius Checkout 8.00 C
   * Sale Vault Referral Response
   */
  public function testCayanCertificationScriptGeniusCheckout8C() {
    $this->setupTransaction();
    $params = [
      'amount' => 3.20,
      'credit_card_number' => '4012000033330026',
    ];
    $results = $this->doPayment($params, 'live');

    $this->assertEquals($results['payment_status_id'], $this->_failedStatusID);
    $this->assertEquals($results['approval_status'], 'REFERRAL;1013');
    $this->spitOutResults('Genius Checkout 8.00 C', $results);
  }

  /**
   * Genius Checkout 12.00 C
   * Decline Duplicate Failure Test
   */
  public function testCayanCertificationScriptGeniusCheckout12C() {
    $this->setupTransaction();
    $params = [
      'amount' => 3.05,
      'credit_card_number' => '4012000033330026',
    ];
    $results = $this->doPayment($params, 'live');

    $this->assertEquals($results['payment_status_id'], $this->_failedStatusID);
    $this->assertEquals($results['approval_status'], 'DECLINED,DUPLICATE;1110;duplicate transaction');
    $this->spitOutResults('Genius Checkout 12.00 C', $results);
  }

  /**
   * Genius Checkout 13.00 C
   * Invalid Expiration Date Failure Test
   */
  public function testCayanCertificationScriptGeniusCheckout13C() {
    $this->setupTransaction();
    $params = [
      'amount' => 1.01,
      'credit_card_number' => '4012000033330026',
      'credit_card_exp_date' => array(
        'M' => '09',
        'Y' => '2000',
      ),
    ];
    $results = $this->doPayment($params, 'live');

    $this->assertEquals($results['payment_status_id'], $this->_failedStatusID);
    $this->assertEquals($results['approval_status'], 'DECLINED;1024;invalid exp date');
    $this->spitOutResults('Genius Checkout 13.00 C', $results);
  }

  /**
   * Genius Checkout 14.00 C
   * Field Format Error Failure Test
   */
  public function testCayanCertificationScriptGeniusCheckout14C() {
    $this->setupTransaction();
    $params = [
      'amount' => 3.10,
      'credit_card_number' => '4012000033330026',
      'cvv2' => '1234',
    ];
    $results = $this->doPayment($params, 'live');

    $this->assertEquals($results['payment_status_id'], $this->_failedStatusID);
    $this->assertEquals($results['approval_status'], 'DECLINED;1007;field format error');
    $this->spitOutResults('Genius Checkout 14.00 C', $results);
  }

  /**
   * Genius Checkout 15.00 C
   * Approval with AVS Failure
   */
  public function testCayanCertificationScriptGeniusCheckout15C() {
    $this->setupTransaction();
    $params = [
      'amount' => 3.41,
      'credit_card_number' => '4012000033330026',
    ];
    $results = $this->doPayment($params, 'live');

    $this->assertEquals($results['payment_status_id'], $this->_completedStatusID);
    $this->spitOutResults('Genius Checkout 15.00 C', $results);
  }

  /**
   * Genius Checkout 16.00 C
   * Approval with CVV Failure
   */
  public function testCayanCertificationScriptGeniusCheckout16C() {
    $this->setupTransaction();
    $params = [
      'amount' => 3.61,
      'credit_card_number' => '4012000033330026',
    ];
    $results = $this->doPayment($params, 'live');


    $this->assertEquals($results['payment_status_id'], $this->_completedStatusID);
    $this->spitOutResults('Genius Checkout 16.00 C', $results);
  }

  /**
   * Genius Checkout 17.00 C
   * Response Failure Test
   */
  public function testCayanCertificationScriptGeniusCheckout17C() {
    $this->setupTransaction();
    $params = [
      'amount' => 7.65,
      'credit_card_number' => '4012000033330026',
    ];
    $results = $this->doPayment($params, 'live');
    $this->assertEquals($results['payment_status_id'], $this->_failedStatusID);
    $this->spitOutResults('Genius Checkout 17.00 C', $results);
  }

  /**
   * Sandbox Tests 7.00 SB
   * MerchantWare 4.5 Sale Random Field
   */
  public function testCayanCertificationScriptSandbox7SB() {
    $this->setupTransaction();
    $params = [
      'amount' => 1.01,
      'credit_card_number' => '4012000033330026',
    ];
    $results = $this->doPayment($params, 'sandbox');
    $this->assertEquals($results['trxn_result_code'], 'MW45SL');
    $this->assertEquals($results['payment_status_id'], $this->_completedStatusID);
    $this->spitOutResults('Sandbox Tests 7.00 SB', $results);
  }
}
