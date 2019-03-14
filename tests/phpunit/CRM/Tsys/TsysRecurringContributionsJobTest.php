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
   * Run a series of cron jobs and make an assertion about email deliveries.
   *
   * @param array $cronRuns
   *   array specifying when to run cron and what messages to expect; each item is an array with keys:
   *   - time: string, e.g. '2012-06-15 21:00:01'
   */
  public function assertCronRuns($time) {
    CRM_Utils_Time::setTime($time);
    $recurJob = civicrm_api3('job', 'tsysrecurringcontributions', array());
    return $recurJob;
  }

  /**
   * Tsys Recurring Job Schedule
   */
  public function testTsysRecurringJobSchedule() {
    $this->setupTransaction();
    $dates = [
      'start_date'                    => "2019-01-01 11:46:27",
      'modified_date'                 => "2019-01-01 11:46:27",
      'create_date'                   => "2019-01-01 11:46:27",
      'next_sched_contribution_date'  => "2019-01-02 00:00:00",
      'contribution_status_id'        => 'In Progress',
    ];
    $recurringContribution = $this->createRecurringContribution($dates);

    $params = [
      'amount' => 10.00,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
      'contributionRecurID' => $recurringContribution['id'],
      'receive_date' => "2019-01-01 11:46:27",
    ];
    $results = $this->doPayment($params);

    $recurringContribution = civicrm_api3('ContributionRecur', 'getsingle', array(
      'id' => $recurringContribution['id'],
      'return' => ["payment_token_id", 'next_sched_contribution_date'],
    ));
    $recurJob = $this->assertCronRuns("2019-01-02 11:46:27");
    print_r($recurJob);
  }

}
