<?php

use CRM_Tsys_ExtensionUtil as E;
use Civi\Test\HeadlessInterface;
use Civi\Test\HookInterface;
use Civi\Test\TransactionalInterface;

require ('BaseTest.php');
class CRM_Tsys_RecurringContributionTsysTest extends CRM_Tsys_BaseTest {
  // Tests of recurring tsys transactions (vault tokens)

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
   * Vault Board Credit by Reference
   */
  public function testCayanCertificationScriptMerchantWare34M() {
    $params = [
      'total_amount' => 10.00,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
    ];
    $contribDetails = $this->processRecurringContribution($params);
    $contribDetails = $this->processRecurringContributionResponse($contribDetails, $this->_completedStatusID);
    $this->spitOutResults('MerchantWARE 4.5 34.00 M', $contribDetails);
  }

  /**
   * MerchantWARE 4.5 37.00 M
   * Sale Vault
   */
  public function testCayanCertificationScriptMerchantWare37M() {
    $params = [
      'total_amount' => 1.01,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
    ];
    $results = $this->processRecurringContribution($params);
    $results = $this->processRecurringContributionResponse($results, $this->_completedStatusID);
    $results['receive_date'] = "2009-07-01 11:53:50";
    $message = CRM_Tsys_Recur::processContributionPayment($results, array(), $results['id']);
    $this->assertEquals($message, ts(
      'Successfully processed recurring contribution in series id %1: ',
      array(1 => $results['contribution_recur_id'])
    ));
    $this->spitOutResults('MerchantWARE 4.5 37.00 M', $results);
  }

 /**
  * MerchantWARE 4.5 42.00
  * Vault Sale on Invalid Token
  */
 public function testCayanCertificationScriptMerchantWare42() {
   $this->setupTransaction();
   $recurringContribution = $this->createRecurringContribution();
   CRM_Tsys_Recur::boardCard($recurringContribution['id'], '987654321', $this->_tsysCreds, $this->_contactID, $this->_paymentProcessorID);

   $contribution = civicrm_api3('Contribution', 'transact', [
     'financial_type_id' => $this->_financialTypeID,
     'total_amount' => 11.00,
     'contact_id' => $this->_contactID,
     'payment_token' => '987654321',
     'payment_processor' => $this->_paymentProcessorID,
     'payment_processor_id' => $this->_paymentProcessorID,
     'currency' => 'USD',
     'is_recur' => 1,
     'contributionRecurID' => $recurringContribution['id'],
     'unit_test' => 1,
   ]);
   $this->assertEquals($contribution['values'][$contribution['id']]['contribution_status_id'], $this->_failedStatusID);
   $this->spitOutResults('MerchantWARE 4.5 42.00', $contribution['values'][$contribution['id']]);
  }

  /**
   * MerchantWARE 4.5 43.00 M
   * Vault Sale on Deleted Token
   */
  public function testCayanCertificationScriptMerchantWare43M() {
    $params = [
      'total_amount' => 1.01,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
    ];
    $results = $this->processRecurringContribution($params);
    $results = $this->processRecurringContributionResponse($results, $this->_completedStatusID);

    $unBoard = CRM_Tsys_Soap::composeUnBoardCardSoapRequest($results['vault_token'], $this->_tsysCreds);
    $results['receive_date'] = "2009-07-01 11:53:50";
    $response = CRM_Tsys_Recur::processContributionPayment($results, array(), $results['id']);
    $this->assertEquals($response, ts(
      'Failed to process recurring contribution id %1: ',
      array(1 => $results['contribution_recur_id'])
    ));
    $this->spitOutResults('MerchantWARE 4.5 43.00 M', $results);
  }

  /**
   * MerchantWARE 4.5 44.00 M
   * Vault Sale Decline Duplicate
   */
  public function testCayanCertificationScriptMerchantWare44M() {
    $params = [
      'total_amount' => 10.00,
      'amount' => 10.00,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
      'invoice_number' => rand(1, 9999999),
    ];
    $contrib1 = $this->processRecurringContribution($params);
    $contrib1 = $this->processRecurringContributionResponse($contrib1, $this->_completedStatusID);
    // pass in contribution recur id from the first contribution
    $params['contributionRecurID'] = $params['contribution_recur_id'] = $contrib1['contribution_recur_id'];
    $contrib2 = $this->processRecurringContribution($params);
    $contrib2 = $this->processRecurringContributionResponse($contrib2, $this->_failedStatusID);
    $this->spitOutResults('MerchantWARE 4.5 44.00 M', $contrib2);
  }

  /**
   * Tsys Recurring Job Schedule
   * Run the 'tsysrecurringcontributions' job test that transaction gets processed
   */
  public function testTsysRecurringJobSchedule() {
    $dates = [
      'start_date'                    => "2019-01-01 11:46:27",
      'modified_date'                 => "2019-01-01 11:46:27",
      'create_date'                   => "2019-01-01 11:46:27",
      'next_sched_contribution_date'  => "2019-01-02 00:00:00",
      'contribution_status_id'        => 'In Progress',
      'payment_instrument_id'         => 'Credit Card',
      'financial_type_id'             => $this->_financialTypeID,
    ];
    $params = [
      'amount' => 10.00,
      'total_amount' => 10.00,
      'contact_id' => $this->contact->id,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
      'receive_date' => "2019-01-01 11:46:27",
      'payment_instrument_id' => 'Credit Card',
      'financial_type_id' => $this->_financialTypeID,
    ];
    $contrib = $this->processRecurringContribution($params, $dates);
    $contrib = $this->processRecurringContributionResponse($contrib, $this->_completedStatusID);
    $recurringContribution = civicrm_api3('ContributionRecur', 'getsingle', array(
      'id' => $contrib['contribution_recur_id'],
      'return' => ["payment_token_id", 'next_sched_contribution_date', 'amount', 'id'],
    ));
    $recurJob = $this->assertCronRuns("2019-01-02 11:46:27");
    $this->assertEquals($recurJob['count'], 1);
    $this->assertEquals($recurJob['is_error'], 0);
  }

}
