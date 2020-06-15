CRM.$(function ($) {

  // hide link to cancel an in progress transaction
  $("span.cancelInProgress").hide();

  $("input.transport").click(function() {

    // Check that all required fields are populated
    var $urlParams = '';
    var allData = 1;

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
      } else {
        $(val).crmError(ts('is a required field'));
        allData = 0;
      }
    });

    // If all required fields are populated
    if (allData == 1) {

      // Show cancel in progress link
      $("span.cancelInProgress").show();
      $('span.crm-button-type-cancel').hide();

      // Compile Transport URL
      var $url = CRM.vars.tsys.transport + $urlParams;

      // Get Transport Key using Transport URL
      $.get($url, function(data) {

        // Send the response to post process.
        if (data.TransportKey.length > 0 && data.status == 'success' && CRM.vars.tsys.ips[$('select#device_id').val()].ip.length > 0) {

          // Compile create Transaction URL
          var $create = "http://" +
          CRM.vars.tsys.ips[$('select#device_id').val()].ip
          + ":8080/v1/pos?TransportKey=" + data.TransportKey + "&Format=JSON";
          if (test == 1) {
            $create = "http://certeng-test.getsandbox.com/pos?TransportKey=" + data.TransportKey + "&Format=JSON";
          }

          // Do create transaction call
          $.get($create, function(response) {

            // Process create transaction response
            var myJson = JSON.stringify(response);
            var processCreate = CRM.vars.tsys.process + $urlParams + "&json=" + myJson;
            console.log(processCreate);
            $.get(processCreate, function() {});
          });
        }
        else {
          $('form').crmError(ts('Invalid Transport Key'));
        }
      });
    }
  });
});
