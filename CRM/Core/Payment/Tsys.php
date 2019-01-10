<?php
/*
 * Payment Processor class for Stripe
 */
class CRM_Core_Payment_Tsys extends CRM_Core_Payment {

  /**
  * We only need one instance of this object. So we use the singleton
  * pattern and cache the instance in this variable
  *
  * @var object
  * @static
  */
 static private $_singleton = NULL;

 /**
  * Mode of operation: live or test.
  *
  * @var object
  */
 protected $_mode = NULL;

 /**
 * TRUE if we are dealing with a live transaction
 *
 * @var boolean
 */
private $_islive = FALSE;

 /**
  * Constructor
  *
  * @param string $mode
  *   The mode of operation: live or test.
  *
  * @return void
  */
 public function __construct($mode, &$paymentProcessor) {
   $this->_mode = $mode;
   $this->_islive = ($mode == 'live' ? 1 : 0);
   $this->_paymentProcessor = $paymentProcessor;
   $this->_processorName = ts('Tsys');
 }


  /**
   * This function checks to see if we have the right config values.
   *
   * @return string
   *   The error message if any.
   *
   * @public
   */
  public function checkConfig() {
    // $config = CRM_Core_Config::singleton();
    $error = array();
    // TODO fix this up to be Tsys specific
    if (empty($this->_paymentProcessor['user_name'])) {
      $error[] = ts('The "Secret Key" is not set in the Tsys Payment Processor settings.');
    }
    if (empty($this->_paymentProcessor['password'])) {
      $error[] = ts('The "Publishable Key" is not set in the Tsys Payment Processor settings.');
    }
    if (!empty($error)) {
      return implode('<p>', $error);
    }
    else {
      return NULL;
    }
  }

  /**
   * Set default values when loading the (payment) form
   *
   * @param \CRM_Core_Form $form
   */
  public function buildForm(&$form) {
    // Get API Key and provide it to JS
    $paymentProcessorId = CRM_Utils_Array::value('id', $form->_paymentProcessor);
    $publishableKey = CRM_Core_Payment_Tsys::getPublishableKey($paymentProcessorId);
    CRM_Core_Resources::singleton()->addVars('tsys', array('api' => $publishableKey));
  }

  /**
   * Given a payment processor id, return the publishable key (password field)
   *
   * @param $paymentProcessorId
   *
   * @return string
   */
  public static function getPublishableKey($paymentProcessorId) {
   try {
     $publishableKey = (string) civicrm_api3('PaymentProcessor', 'getvalue', array(
       'return' => "password",
       'id' => $paymentProcessorId,
     ));
   }
   catch (CiviCRM_API3_Exception $e) {
     return '';
   }
   return $publishableKey;
  }
}
