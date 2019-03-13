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
  * Contribute.Transact API
  */
  public function testCayanContributeTransact() {
    $this->setupTransaction();
    $recurringContribution = $this->createRecurringContribution();
    $params = [
      'amount' => 10.00,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
      'contributionRecurID' => $recurringContribution['id'],
    ];
    $results = $this->doPayment($params);
    $results['financial_type_id'] = 1;
    $results['total_amount'] = $results['amount'];
    $results['contact_id'] = $results['contactID'];

    $firstContribution = civicrm_api3('Contribution', 'create', $results);

    $this->assertEquals($results['trxn_result_code'], 'NC1000');
    $this->assertEquals($results['payment_status_id'], $this->_completedStatusID);
    $this->assertGreaterThan(0, $results['payment_token_id']);

    $paymentToken = civicrm_api3('PaymentToken', 'getsingle', [
      'id' => $results['payment_token_id'],
    ]);
    $results['contribution_recur_id'] = $recurringContribution['id'];
    $results['vault_token'] = $paymentToken['token'];
    $results['payment_processor'] =  $results['payment_processor_id'];
    $results['receive_date'] = "2009-07-01 11:53:50";
    $contribution = civicrm_api3('Contribution', 'transact', [
      'financial_type_id' => 1,
      'total_amount' => 11.00,
      'contact_id' => $results['contact_id'],
      'payment_token' => $results['vault_token'],
      'payment_processor' => $results['payment_processor_id'],
      'payment_processor_id' => $results['payment_processor_id'],
      'currency' => 'USD',
    ]);
    $this->assertEquals($contribution['values'][$contribution['id']]['contribution_status_id'], $this->_completedStatusID);
    $this->spitOutResults('Contribute Transact API', $contribution['values'][$contribution['id']]);
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

  /**
  * MerchantWARE 4.5 38.00 M
  */
  public function testCayanCertificationScriptMerchantWare38M() {
    $this->setupTransaction();
    $recurringContribution = $this->createRecurringContribution();
    $params = [
      'amount' => 10.00,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
      'contributionRecurID' => $recurringContribution['id'],
    ];
    $results = $this->doPayment($params);
    $results['financial_type_id'] = 1;
    $results['total_amount'] = $results['amount'];
    $results['contact_id'] = $results['contactID'];

    $firstContribution = civicrm_api3('Contribution', 'create', $results);

    $this->assertEquals($results['trxn_result_code'], 'NC1000');
    $this->assertEquals($results['payment_status_id'], $this->_completedStatusID);
    $this->assertGreaterThan(0, $results['payment_token_id']);

    $paymentToken = civicrm_api3('PaymentToken', 'getsingle', [
      'id' => $results['payment_token_id'],
    ]);
    $results['contribution_recur_id'] = $recurringContribution['id'];
    $results['vault_token'] = $paymentToken['token'];
    $results['payment_processor'] =  $results['payment_processor_id'];
    $results['receive_date'] = "2009-07-01 11:53:50";
    CRM_Tsys_Recur::processContributionPayment($results, array(), $firstContribution['id']);
    $this->spitOutResults('MerchantWARE 4.5 38.00 M', $results);
  }
}
