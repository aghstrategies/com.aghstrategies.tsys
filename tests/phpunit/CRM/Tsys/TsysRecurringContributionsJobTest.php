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
   * Run the Tsysrecurringcontributions cron job
   * @param  string $time time to run the job as
   * @return array        results from the job
   */
  public function assertCronRuns($time) {
    CRM_Utils_Time::setTime($time);
    $recurJob = civicrm_api3('job', 'tsysrecurringcontributions', array());
    return $recurJob;
  }

  /**
   * Tsys Recurring Job Schedule
   * Run the 'tsysrecurringcontributions' job test that transaction gets processed
   */
  public function testTsysRecurringJobSchedule() {
    $this->setupTransaction();
    $dates = [
      'start_date'                    => "2019-01-01 11:46:27",
      'modified_date'                 => "2019-01-01 11:46:27",
      'create_date'                   => "2019-01-01 11:46:27",
      'next_sched_contribution_date'  => "2019-01-02 00:00:00",
      'contribution_status_id'        => 'In Progress',
      'payment_instrument_id' => array_search('Credit Card', $this->_paymentInstruments),
      'financial_type_id' => $this->_financialTypeID,
    ];
    $recurringContribution = $this->createRecurringContribution($dates);

    $params = [
      'amount' => 10.00,
      'total_amount' => 10.00,
      'contact_id' => $this->contact->id,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
      'contribution_recur_id'=> $recurringContribution['id'],
      'contributionRecurID' => $recurringContribution['id'],
      'receive_date' => "2019-01-01 11:46:27",
      'payment_instrument_id' => array_search('Credit Card', $this->_paymentInstruments),
      'financial_type_id' => $this->_financialTypeID,
    ];
    $results = $this->doPayment($params);
    $contribution = civicrm_api3('Contribution', 'create', $results);
    $recurringContribution = civicrm_api3('ContributionRecur', 'getsingle', array(
      'id' => $recurringContribution['id'],
      'return' => ["payment_token_id", 'next_sched_contribution_date', 'amount', 'id'],
    ));
    $recurJob = $this->assertCronRuns("2019-01-02 11:46:27");
    $this->assertEquals($recurJob['count'], 1);
    $this->assertEquals($recurJob['is_error'], 0);
  }
}