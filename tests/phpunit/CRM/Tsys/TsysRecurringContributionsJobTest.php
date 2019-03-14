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
  public function assertCronRuns($cronRuns) {
    foreach ($cronRuns as $cronRun) {
      CRM_Utils_Time::setTime($cronRun['time']);
      civicrm_api3('job', 'tsysrecurringcontributions', array());
    }
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
      'next_sched_contribution_date'  => "2019-01-02 11:46:27",
    ];
    $recurringContribution = $this->createRecurringContribution($dates);
    $params = [
      'amount' => 10,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
      'contributionRecurID' => $recurringContribution['id'],
      'receive_date' => "2019-01-01 11:46:27",
    ];
    $results = $this->doPayment($params);
    $results['financial_type_id'] = $this->_financialTypeID;
    $results['total_amount'] = $results['amount'];
    $results['contact_id'] = $results['contactID'];

    $firstContribution = civicrm_api3('Contribution', 'create', $results);
    $this->assertEquals($results['payment_status_id'], $this->_completedStatusID);
    $this->assertGreaterThan(0, $results['payment_token_id']);

    $paymentToken = civicrm_api3('PaymentToken', 'getsingle', [
      'id' => $results['payment_token_id'],
    ]);
    $results['contribution_recur_id'] = $recurringContribution['id'];
    $results['payment_processor'] =  $results['payment_processor_id'];
    $results['receive_date'] = "2009-07-01 11:53:50";
    $results['payment_token'] = $paymentToken['token'];
    $response = CRM_Tsys_Recur::processContributionPayment($results, array(), $firstContribution['id']);
    $this->spitOutResults('Tsys Recurring Job Schedule', $results);
  }

}
