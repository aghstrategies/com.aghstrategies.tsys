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
    $results = $this->doPayment($params, 'live');
    $results['financial_type_id'] = $this->_financialTypeID;
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
      'financial_type_id' => $this->_financialTypeID,
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
   * Vault Board Credit by Reference
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
    $results = $this->doPayment($params, 'live');
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
   * MerchantWARE 4.5 37.00 M
   * Sale Vault
   */
  public function testCayanCertificationScriptMerchantWare37M() {
    $this->setupTransaction();
    $recurringContribution = $this->createRecurringContribution();
    $params = [
      'amount' => 1.01,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
      'contributionRecurID' => $recurringContribution['id'],
    ];
    $results = $this->doPayment($params, 'live');
    $results['financial_type_id'] = $this->_financialTypeID;
    $results['total_amount'] = $results['amount'];
    $results['contact_id'] = $results['contactID'];

    $firstContribution = civicrm_api3('Contribution', 'create', $results);

    $this->assertEquals($results['trxn_result_code'], 'SAL101');
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
   ]);
   $this->assertEquals($contribution['values'][$contribution['id']]['contribution_status_id'], $this->_completedStatusID);
   $this->spitOutResults('MerchantWARE 4.5 42.00', $contribution['values'][$contribution['id']]);
  }

  /**
   * MerchantWARE 4.5 43.00 M
   * Vault Sale on Deleted Token
   */
  public function testCayanCertificationScriptMerchantWare43M() {
    $this->setupTransaction();
    $recurringContribution = $this->createRecurringContribution();
    $params = [
      'amount' => 1.01,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
      'contributionRecurID' => $recurringContribution['id'],
    ];
    $results = $this->doPayment($params, 'live');
    $results['financial_type_id'] = $this->_financialTypeID;
    $results['total_amount'] = $results['amount'];
    $results['contact_id'] = $results['contactID'];

    $firstContribution = civicrm_api3('Contribution', 'create', $results);

    $this->assertEquals($results['trxn_result_code'], 'SAL101');
    $this->assertEquals($results['payment_status_id'], $this->_completedStatusID);
    $this->assertGreaterThan(0, $results['payment_token_id']);

    $paymentToken = civicrm_api3('PaymentToken', 'getsingle', [
      'id' => $results['payment_token_id'],
    ]);
    $unBoard = CRM_Tsys_Soap::composeUnBoardCardSoapRequest($paymentToken['token'], $this->_tsysCreds);
    $results['contribution_recur_id'] = $recurringContribution['id'];
    $results['payment_processor'] =  $results['payment_processor_id'];
    $results['receive_date'] = "2009-07-01 11:53:50";
    $results['payment_token'] = $paymentToken['token'];
    $response = CRM_Tsys_Recur::processContributionPayment($results, array(), $firstContribution['id']);
    $this->spitOutResults('MerchantWARE 4.5 43.00 M', $results);
  }

  /**
   * MerchantWARE 4.5 44.00 M
   * Vault Sale Decline Duplicate
   */
  public function testCayanCertificationScriptMerchantWare44M() {
    $this->setupTransaction();
    $recurringContribution = $this->createRecurringContribution();
    $params = [
      'amount' => 10.00,
      'credit_card_number' => '4012000033330026',
      'is_recur' => 1,
      'contributionRecurID' => $recurringContribution['id'],
      'invoice_number' => 1,
    ];
    $contrib1 = $this->doPayment($params, 'live');
    $contrib2 = $this->doPayment($params, 'live');
    $this->assertEquals($contrib2['payment_status_id'], $this->_failedStatusID);
    $this->spitOutResults('MerchantWARE 4.5 44.00 M', $contrib2);
  }

  /**
   * CRM-20745: Test the submit function correctly sets the
   * receive date for recurring contribution.
   */
  public function testSubmitCreditCardWithRecur() {
    $mode = 'test';
    $pp = $this->_paymentProcessor;
    $tsys = new CRM_Core_Payment_Tsys($mode, $pp);
    $this->setupTransaction();
    $receiveDate = date('Y-m-d H:i:s', strtotime('+1 month'));
    $contributionParams = array(
      'total_amount' => 1.01,
      'financial_type_id' => 1,
      'is_recur' => 1,
      'frequency_interval' => 2,
      'frequency_unit' => 'month',
      'installments' => 2,
      'receive_date' => $receiveDate,
      'contact_id' => $this->_contactID,
      'payment_instrument_id' => array_search('Credit Card', $this->_paymentInstruments),
      'payment_processor_id' => $this->_paymentProcessorID,
      'credit_card_exp_date' => array(
        'M' => '09',
        'Y' => '2022',
      ),
      'credit_card_number' => '4012000033330026',
      'cvv2' => 123,
      'billing_city-5' => 'Vancouver',
      'billing_first_name' => 'Jane',
      'billing_last_name' => 'Doe',
      'location_type_id' => 5,
      'amount' => 1.01,
      'invoice_number' => rand(1, 1000000),
      'source' => 'source',
    );
    $tsysCreds = $tsys::getPaymentProcessorSettings($this->_paymentProcessorID, array("signature", "subject", "user_name"));
    $makeTransaction = $this->generateTokenFromCreditCard($contributionParams, $tsysCreds);
    $ret = $tsys->processTransaction($makeTransaction, $contributionParams, $tsysCreds);
    $ret['payment_token'] = $ret['tsys_token'];
    $form = new CRM_Contribute_Form_Contribution();
    $form->testSubmit($ret, CRM_Core_Action::ADD, 'live');
    $contribution = civicrm_api3('Contribution', 'getsingle', array('return' => 'receive_date'));
    $this->assertEquals($contribution['receive_date'], $receiveDate);
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
    $results = $this->doPayment($params, 'live');
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
