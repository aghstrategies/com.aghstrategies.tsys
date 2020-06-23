CRM.$(function ($) {
  var onclickAction = null;

  $(document).ready(function () {
    // hide link to cancel an in progress transaction
    $("span.cancelInProgress").hide();
    $("input#tsys_initiate_response").parent().parent().hide();
    $("input#tsys_create_response").parent().parent().hide();
  });

  var validateForm = function() {
    // validate form
    var allData = 1;
    $.each({
      amount: 'input#total_amount',
      device: 'select#device_id',
      fintype: 'input#financial_type_id',
      contact: 'input[name="contact_id"]',
    }, function(name, val) {
      if ($(val).val() == undefined || $(val).val() == '') {
        $(val).crmError(ts('is a required field'));
        allData = 0;
      }
    });
    return allData;
  };

  var compileURL = function(baseUrl) {
    // compile url parameters
    var $urlParams = '';

    // Is test?
    var test = 0;
    if ($('input#is_test').prop('checked')) {
      test = 1;
    }
    $urlParams = $urlParams + "&test=" + test;

    $.each({
      amount: 'input#total_amount',
      device: 'select#device_id',
      fintype: 'input#financial_type_id',
      contact: 'input[name="contact_id"]',
    }, function(name, val) {
      if ($(val).val() != undefined && $(val).val().length) {
        $urlParams = $urlParams + "&" + name + "=" + $(val).val();
      }
    });
    return baseUrl + $urlParams;
  };

  function compileCreateTransactionURL(data) {
    // Is test?
    var test = 0;
    if ($('input#is_test').prop('checked')) {
      test = 1;
    }

    // sniff https or http set up url accordingly
    if (window.location.protocol == 'https:') {
      var $create = "https://" +
      CRM.vars.tsys.ips[$('select#device_id').val()].ip
      + ":8443/v1/pos?TransportKey=" + data.TransportKey + "&Format=JSON";
      if (test == 1) {
        $create = "https://certeng-test.getsandbox.com/pos?TransportKey=" + data.TransportKey + "&Format=JSON";
      }
    }
    else {
      var $create = "http://" +
      CRM.vars.tsys.ips[$('select#device_id').val()].ip
      + ":8080/v1/pos?TransportKey=" + data.TransportKey + "&Format=JSON";
      if (test == 1) {
        $create = "http://certeng-test.getsandbox.com/pos?TransportKey=" + data.TransportKey + "&Format=JSON";
      }
    }
    return $create;
  }

  function transportSuccess(data,status,xhr) {

    var initiateResponse = JSON.stringify(data);
    $('input#tsys_initiate_response').val(initiateResponse);

    if (data.TransportKey.length > 0 && data.status == 'success' && CRM.vars.tsys.ips[$('select#device_id').val()].ip.length > 0) {
      $create = compileCreateTransactionURL(data);
      $.ajax({
        url: $create,
        type: 'get',
        async: false,
        success: createSuccess,
        error: transportError,
      });
    }
  }

  function createSuccess(response,status,xhr) {
    var createResponse = JSON.stringify(response);
    $('input#tsys_create_response').val(createResponse);
  }

  function transportError(xhr,status,error) {
    console.log(error);
  }

  function sendInfoToTsys(e) {

    // If all required fields are populated
    allData = validateForm();

    // If form is valid (all required fields are populated)
    if (allData == 1) {

      // Show cancel in progress link
      $("span.cancelInProgress").show();
      $('span.crm-button-type-cancel').hide();

      // Compile Transport URL
      var $url = compileURL(CRM.vars.tsys.transport);

      $.ajax({
        url: $url,
        type: 'get',
        async: false,
        success: transportSuccess,
        error: transportError,
      });
    }
  }

  $('form.CRM_Tsys_Form_Device').submit(sendInfoToTsys);

});
