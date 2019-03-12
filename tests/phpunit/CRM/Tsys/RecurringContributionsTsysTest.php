<?php

use CRM_Tsys_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

require ('BaseTest.php');
class CRM_Tsys_ContributionTsysTest extends CRM_Tsys_BaseTest {


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
  * MerchantWARE 4.5 34.00 M
  */
  public function testCayanCertificationScriptMerchantWare34M() {
    $this->setupTransaction();
    $recurringContribution = $this->createRecurringContribution();
    $params = [
      'amount' => 10.00,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
      'contributionRecurID' => $recurringContribution['id'],
    ];
    $results = $this->doPayment($params);
    $this->assertEquals($results['trxn_result_code'], 'NC1000');
    $this->assertEquals($results['payment_status_id'], $this->_completedStatusID);
    $this->assertGreaterThan(0, $results['payment_token_id']);
    $paymentToken = civicrm_api3('PaymentToken', 'getsingle', [
      'id' => $results['payment_token_id'],
    ]);
    $results['vault_token'] = $paymentToken['token'];
    $this->spitOutResults('MerchantWARE 4.5 34.00 M', $results);
  }
}
