CRM.$(function ($) {
  var onclickAction = null;

  $(document).ready(function () {
    // hide link to cancel an in progress transaction
    $("span.cancelInProgress").hide();

    var $submit = $("input.crm-form-submit.validate");
    // Store and remove any onclick Action currently assigned to the form.
    // We will re-add it if the transaction goes through.
    onclickAction = $submit.attr('onclick');
    $submit.removeAttr('onclick');

  });

  var validateForm = function() {
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
    // Check that all required fields are populated
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

    // TODO sniff https or http set up url accordingly
    // Compile create Transaction URL
    var $create = "http://" +
    CRM.vars.tsys.ips[$('select#device_id').val()].ip
    + ":8080/v1/pos?TransportKey=" + data.TransportKey + "&Format=JSON";
    if (test == 1) {
      $create = "http://certeng-test.getsandbox.com/pos?TransportKey=" + data.TransportKey + "&Format=JSON";
    }

    return $create;
  }

  $('form.CRM_Tsys_Form_Device').submit(function() {
    // If all required fields are populated
    allData = validateForm();
    console.log(allData);
    // If form is valid (all required fields are populated)
    if (allData == 1) {

      // Show cancel in progress link
      $("span.cancelInProgress").show();
      $('span.crm-button-type-cancel').hide();

      // Compile Transport URL
      var $url = compileURL(CRM.vars.tsys.transport);

      // Get Transport Key using Transport URL
      $.get($url).done(function(data, textStatus, jqXHR) {
        if (data.TransportKey.length > 0 && data.status == 'success' && CRM.vars.tsys.ips[$('select#device_id').val()].ip.length > 0) {
          $create = compileCreateTransactionURL(data);
          $.get($create).done(function(response, textStatus, jqXHR) {
            var myJson = JSON.stringify(response);
            $('input#tsys_response').val(myJson);
            return true;
          })
        }
      })
      .fail(function(jqXHR, textStatus, errorThrown) {
          console.log(jqXHR);
          console.log(textStatus);
          console.log(errorThrown );
      });
    }
    // return false;
  });
});
