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

  public function testCayanCertificationScriptGeniusCheckout() {
    // $this->setupTransaction();
    $testCases = [
      // Sale Vault: Process a Sale transaction using the single-use token received.
      'Genius Checkout 1.00 C' => [
        'assertions' => [
          'trxn_result_code' => 'SAL101',
          'payment_status_id' => $this->_completedStatusID,
        ],
        'params' => [
          'amount' => 1.01,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Sale Vault Decline Response
      'Genius Checkout 7.00 C' => [
        'assertions' => [
          'approval_status' => 'DECLINED;1012;decline',
          'payment_status_id' => $this->_failedStatusID,
        ],
        'params' => [
          'amount' => 3.01,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Sale Vault Referral Response
      'Genius Checkout 8.00 C' => [
        'assertions' => [
          'approval_status' => 'REFERRAL;1013',
          'payment_status_id' => $this->_failedStatusID,
        ],
        'params' => [
          'amount' => 3.20,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Decline Duplicate Failure Test
      'Genius Checkout 12.00 C' => [
        'assertions' => [
          'approval_status' => 'DECLINED,DUPLICATE;1110;duplicate transaction',
          'payment_status_id' => $this->_failedStatusID,
        ],
        'params' => [
          'amount' => 3.05,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Invalid Expiration Date Failure Test
      'Genius Checkout 13.00 C' => [
        'assertions' => [
          'approval_status' => 'DECLINED;1024;invalid exp date',
          'payment_status_id' => $this->_failedStatusID,
        ],
        'params' =>  [
          'amount' => 1.01,
          'credit_card_number' => '4012000033330026',
          'credit_card_exp_date' => array(
            'M' => '09',
            'Y' => '2000',
          ),
        ],
      ],
      // Field Format Error Failure Test
      'Genius Checkout 14.00 C' => [
        'assertions' => [
          'approval_status' => 'DECLINED;1007;field format error',
          'payment_status_id' => $this->_failedStatusID,
        ],
        'params' =>  [
          'amount' => 3.10,
          'credit_card_number' => '4012000033330026',
          'cvv2' => '1234',
        ],
      ],
      // Approval with AVS Failure
      'Genius Checkout 15.00 C' => [
        'assertions' => [
          'payment_status_id' => $this->_completedStatusID,
        ],
        'params' => [
          'amount' => 3.41,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Approval with CVV Failure
      'Genius Checkout 16.00 C' => [
        'assertions' => [
          'payment_status_id' => $this->_completedStatusID,
        ],
        'params' => [
          'amount' => 3.61,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Response Failure Test
      'Genius Checkout 17.00 C' => [
        'assertions' => [
          'payment_status_id' => $this->_failedStatusID,
        ],
        'params' => [
          'amount' => 7.65,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Sandbox Tests 7.00 SB - MerchantWare 4.5 Sale Random Field
      'Sandbox Tests 7.00 SB' => [
        'assertions' => [
          'payment_status_id' => $this->_completedStatusID,
          'trxn_result_code' => 'MW45SL',
        ],
        'params' => [
          'amount' => 1.01,
          'credit_card_number' => '4012000033330026',
          'is_test' => 1,
        ],
      ],
    ];

    foreach ($testCases as $testTitle => $testDetails) {
      $params = $testDetails['params'];
      $results = $this->doPayment($testDetails['params'], 'live');
      foreach ($testDetails['assertions'] as $field => $expectedValue) {
        $this->assertEquals($results[$field], $expectedValue);
      }
      $this->spitOutResults($testTitle, $results);
    }
  }
}
