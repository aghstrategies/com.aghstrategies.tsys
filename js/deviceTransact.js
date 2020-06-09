CRM.$(function ($) {
  $("<span class='crm-button crm-i-button transport'><i class='crm-i fa-check'></i><input crm-icon='fa-times' class='crm-form-submit transport crm-button' value='Transport'></span>").insertAfter('span.crm-button-type-submit');
  $("input.transport").click(function() {
    var test = 0;
    if ($('input#is_test').prop('checked')) {
      test = 1;
    }
    // TODO deal with if things go wrong
    var $url = CRM.vars.tsys.transport + "&device=" + $('select#device_id').val() + "&amount=" + $('input#total_amount').val() + "&test=" + test;
    $.get($url, function(data) {
      console.log(data);
      // Send the response to post process.
      if (data.TransportKey.length > 0 && data.status == 'success' && CRM.vars.tsys.ips[$('select#device_id').val()].ip.length > 0) {
        // TODO deal with test transactions
        var $create = "http://" + CRM.vars.tsys.ips[$('select#device_id').val()].ip + ":8080/v1/pos?TransportKey=" + data.TransportKey + "&Format=JSON";
        $.get($create, function(response) {
          // send response to post process
          console.log(response);
        });
      }
    });
  });
});
