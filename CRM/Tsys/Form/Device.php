<?php

use CRM_Tsys_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Tsys_Form_Device extends CRM_Core_Form {
  public function buildQuickForm() {
    $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('buttons');
    $deviceOptions = $this->getDeviceOptions($deviceSettings);
    $this->add(
      'select', // field type
      'device_id', // field name
      'Device', // field label
      $deviceOptions,
      TRUE // is required
    );

    $this->addEntityRef('contact_id', ts('Select Contact'), [], TRUE);
    $this->addEntityRef('financial_type_id', ts('Financial Type'), [
      'entity' => 'FinancialType',
      'select' => ['minimumInputLength' => 0],
    ], TRUE);

    $this->add('text', 'total_amount', "Total Amount", TRUE);

   // Set defaults
   $defaults = [];
   if ($_GET['cid']) {
     $defaults['contact_id'] = $_GET['cid'];
   }
   if ($_GET['deviceid']) {
     $defaults['device_id'] = $_GET['deviceid'];
   }
   $this->setDefaults($defaults);

   // 'receive_date' => "",

    $this->addButtons(array(
      array(
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ),
    ));

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());
    parent::buildQuickForm();
  }

  public function postProcess() {
    // TODO need to fixup ids of devices so they are unique even if there are multiple processors with devices
    $values = $this->exportValues();
    $deviceSettings = CRM_Core_Payment_Tsys::getDeviceSettings('buttons');
    if (!empty($deviceSettings[$values['device_id']])) {
      $deviceWeAreUsing = $deviceSettings[$values['device_id']];
      $tsysCreds = CRM_Core_Payment_Tsys::getPaymentProcessorSettings($deviceWeAreUsing['processorid']);
      $response = CRM_Tsys_Soap::composeStageTransaction($tsysCreds, $values['total_amount']);
      $response = CRM_Core_Payment_TsysDevice::processStageTransactionResponse($response);
      if (!empty($response['TransportKey'])) {
        $url = "http://{$deviceWeAreUsing['ip']}:8080/v1/pos?TransportKey={$response['TransportKey']}&Format=JSON";
        $responseFromDevice = CRM_Core_Payment_TsysDevice::curlapicall($url);
        if ($responseFromDevice->Status == 'APPROVED' && $responseFromDevice->TransactionType == 'SALE') {
          // TODO start here.. need to process response and create contribution!
          // processResponseFromTsys($values, $responseFromDevice, 'response');
          print_r($values); die();
          // $params = $this->formatResponse($responseFromDevice, $values);
          print_r($params); die();
          // $values['receive_date'] = $responseFromDevice->TransactionDate;
          CRM_Core_Payment_Tsys::doPayment($params);
          // $makeTransaction = CRM_Tsys_Soap::composeSaleSoapRequestToken(
          //   $responseFromDevice->Token,
          //   $tsysCreds,
          //   $values['total_amount'],
          //   rand(1, 9999999)
          // );
          // print_r($makeTransaction); die();
        }
      }


  // $responseFromDevice looks like:
  //       stdClass Object
  // (
  //     [Status] => APPROVED
  //     [AmountApproved] => 3.00
  //     [AuthorizationCode] => OK9999
  //     [Cardholder] => TEST CARD/GENIUS
  //     [AccountNumber] => ************0026
  //     [PaymentType] => VISA
  //     [EntryMode] => SWIPE
  //     [ErrorMessage] =>
  //     [Token] => 3177255465
  //     [TransactionDate] => 5/7/2020 6:46:37 PM
  //     [TransactionType] => SALE
  //     [ResponseType] => SINGLE
  //     [ValidationKey] => faeb97f2-402b-4d41-b8ad-2f3b6bd076d2
        // TODO process response from Device
        // TODO Make transaction with token
        // TODO record payment in CiviCRM
        // TODO make this play nicely with the back end credit card form
      }
      // TODO parse response
      // print_r($response); die();

      // CRM_Core_Session::setStatus(E::ts('You picked color "%1"', array(
      //   1 => $options[$values['favorite_color']],
      // )));
      // parent::postProcess();
  }

  public function getDeviceOptions($deviceSettings) {
    $options = [];
    foreach ($deviceSettings as $key => $value) {
      if (!empty($value['devicename'])) {
        $options[$key] = $value['devicename'];
      }
    }
    return $options;
  }

  // public function formatResponse($responseFromDevice, $values) {
  //   foreach ($responseFromDevice as $key => $value) {
  //     print_r($key);
  //     switch ($key) {
  //
  //       case 'TransactionDate':
  //         $values['receive_date'] = $value;
  //         break;
  //
  //       case 'AdditionalParameters':
  //         break;
  //
  //       default:
  //         $values[$key] = $value;
  //         break;
  //     }
  //     return $values;
  //   }
  // }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames() {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = array();
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }

}
