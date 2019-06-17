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
          // 'trxn_result_code' => 'MW45SL',
          'contribution_status_id' => $this->_completedStatusID,
        ],
        'params' => [
          'total_amount' => 1.01,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Sale Vault Decline Response
      'Genius Checkout 7.00 C' => [
        'assertions' => [
          // 'approval_status' => 'DECLINED;1012;decline',
          'contribution_status_id' => $this->_failedStatusID,
        ],
        'params' => [
          'total_amount' => 3.40,
          'amount' => 3.40,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Sale Vault Referral Response
      'Genius Checkout 8.00 C' => [
        'assertions' => [
          // 'approval_status' => 'REFERRAL;1013',
          'contribution_status_id' => $this->_failedStatusID,
        ],
        'params' => [
          'total_amount' => 3.20,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Decline Duplicate Failure Test
      'Genius Checkout 12.00 C' => [
        'assertions' => [
          // 'approval_status' => 'DECLINED,DUPLICATE;1110;duplicate transaction',
          'contribution_status_id' => $this->_failedStatusID,
        ],
        'params' => [
          'total_amount' => 3.05,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Invalid Expiration Date Failure Test
      'Genius Checkout 13.00 C' => [
        'assertions' => [
          // 'approval_status' => 'DECLINED;1024;invalid exp date',
          'contribution_status_id' => $this->_failedStatusID,
        ],
        'params' => [
          'total_amount' => 1.01,
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
          // 'approval_status' => 'DECLINED;1007;field format error',
          'contribution_status_id' => $this->_failedStatusID,
        ],
        'params' => [
          'total_amount' => 3.10,
          'credit_card_number' => '4012000033330026',
          'cvv2' => '1234',
        ],
      ],
      // Approval with AVS Failure
      'Genius Checkout 15.00 C' => [
        'assertions' => [
          'contribution_status_id' => $this->_completedStatusID,
        ],
        'params' => [
          'total_amount' => 3.41,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Approval with CVV Failure
      'Genius Checkout 16.00 C' => [
        'assertions' => [
          'contribution_status_id' => $this->_completedStatusID,
        ],
        'params' => [
          'total_amount' => 3.61,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // Response Failure Test
      'Genius Checkout 17.00 C' => [
        'assertions' => [
          'contribution_status_id' => $this->_failedStatusID,
        ],
        'params' => [
          'total_amount' => 7.65,
          'credit_card_number' => '4012000033330026',
        ],
      ],
      // // Sandbox Tests 7.00 SB - MerchantWare 4.5 Sale Random Field
      // 'Sandbox Tests 7.00 SB' => [
      //   'assertions' => [
      //     'contribution_status_id' => $this->_completedStatusID,
      //     // 'trxn_result_code' => 'SAL101',
      //   ],
      //   'params' => [
      //     'total_amount' => 1.01,
      //     'credit_card_number' => '4012000033330026',
      //     'is_test' => 1,
      //     'payment_processor_id' => $this->_testPaymentProcessorID,
      //     'payment_processor' => $this->_testPaymentProcessorID,
      //   ],
      // ],
    ];

    foreach ($testCases as $testTitle => $testDetails) {
      $params = $this->preparePayment($testDetails['params']);
      try {
        $contribution = civicrm_api3('Contribution', 'transact', $params);
      }
      catch (CiviCRM_API3_Exception $e) {
        $error = $e->getMessage();
        CRM_Core_Error::debug_log_message(ts('API Error %1', array(
          'domain' => 'com.aghstrategies.tsys',
          1 => $error,
        )));
      }
      if (!empty($contribution['values'][0]['contribution_status_id'])) {
        foreach ($testDetails['assertions'] as $field => $expectedValue) {
          $this->assertEquals($expectedValue, $contribution['values'][0]['contribution_status_id']);
        }
        $this->spitOutResults($testTitle, $contribution['values'][0]);
      }
    }
  }

  /**
   * Test the submit function on the contribution page.
   */
  public function testSubmitCreditCardTsys1() {
    $mut = new CiviMailUtils($this, TRUE);
    $mut->clearMessages();
    $form = new CRM_Contribute_Form_Contribution();
    $form->_mode = 'Live';
    $error = FALSE;
    try {
      $form->testSubmit(array(
        'total_amount' => 50,
        'financial_type_id' => 1,
        'contact_id' => $this->_contactID,
        'contribution_status_id' => 1,
        'credit_card_number' => 4444333322221111,
        'cvv2' => 123,
        'credit_card_exp_date' => array(
          'M' => 9,
          'Y' => 2025,
        ),
        'credit_card_type' => 'Visa',
        'billing_first_name' => 'Junko',
        'billing_middle_name' => '',
        'billing_last_name' => 'Adams',
        'billing_street_address-5' => '790L Lincoln St S',
        'billing_city-5' => 'Maryknoll',
        'billing_state_province_id-5' => 1031,
        'billing_postal_code-5' => 10545,
        'billing_country_id-5' => 1228,
        'frequency_interval' => 1,
        'frequency_unit' => 'month',
        'installments' => '',
        'hidden_AdditionalDetail' => 1,
        'hidden_Premium' => 1,
        'from_email_address' => '"civi45" <civi45@civicrm.com>',
        'is_email_receipt' => TRUE,
        'receipt_date' => '',
        'receipt_date_time' => '',
        'payment_processor_id' => $this->_paymentProcessorID,
        'currency' => 'USD',
        'source' => 'bob sled race',
      ), CRM_Core_Action::ADD);
    }
    catch (Civi\Payment\Exception\PaymentProcessorException $e) {
      $error = TRUE;
    }

    $contribution = civicrm_api3('Contribution', 'get', array(
      'contact_id' => $this->_contactID,
      'contribution_status_id' => $error ? 'Failed' : 'Completed',
    ));

    $this->assertEquals(1, $contribution["count"], "Contribution count should be one.");
    $this->assertTrue(!empty($contribution["values"][$contribution["id"]]["receipt_date"]), "Receipt date should not be blank.");

    $contact = civicrm_api3('Contact', 'getsingle', array('id' => $this->_contactID));
    $this->assertTrue(empty($contact['source']));
    if (!$error) {
      $msgs = $mut->getAllMessages();
      $this->assertEquals(1, count($msgs));
    }
    $mut->clearMessages();
    $mut->stop();
  }

}
