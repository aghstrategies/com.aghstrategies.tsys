CRM.$(function ($) {
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
