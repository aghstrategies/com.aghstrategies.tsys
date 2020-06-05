CRM.$(function ($) {
  $("<span class='crm-button crm-i-button transport'><i class='crm-i fa-check'></i><input crm-icon='fa-times' class='crm-form-submit transport crm-button' value='Transport'></span>").insertAfter('span.crm-button-type-submit');

  console.log($('select#device_id').val());
  console.log($('input#total_amount').val());
  console.log(CRM.vars.tsys.transport);
  $("input.transport").click(function() {
    console.log('clicked');
    var $url = CRM.vars.tsys.transport + "&device=" + $('select#device_id').val() + "&amount=" + $('input#total_amount').val() + "&test=" + $('input#is_test').val();
    console.log($url);
    $url = 'http://ausm.localhost/wp-admin/admin.php?page=CiviCRM&q=civicrm%2Ftsys%2Ftransportkey&device=1&amount=1&test=0'
    $.get($url, function(data) {
      // TODO now that we have the transport key make the create transaction call same as the cancel call.
      // Send the response to post process.
      console.log(data);
      CRM.alert(data, 'result', 'error', []);
    });

    // console.log($('select#device_id').val());
    // console.log($('input#total_amount').val());
    // $.get("http://" + $ip + ":8080/v1/pos?Action=Cancel&Format=JSON", function(data) {
    //   if (data.Status == "Denied") {
    //     CRM.alert(data.ResponseMessage + " click 'Cancel In Progress Transaction' button again", data.Status, 'info', []);
    //   }
    //   if (data.Status == "Failed") {
    //     CRM.alert(data.ResponseMessage, data.Status, 'error', []);
    //   }
    // });

  });
});
