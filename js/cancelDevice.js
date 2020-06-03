CRM.$(function ($) {

  $("<span class='crm-button crm-i-button cancelInProgress'><i class='crm-i fa-times'></i><input crm-icon='fa-times' class='crm-form-submit cancelInProgress crm-button' value='Cancel In Progress Transaction'></span>").insertAfter('span.crm-button-type-cancel');
  $("span.cancelInProgress").hide();

  $('input.crm-form-submit').click(function () {
    $("span.cancelInProgress").show();
    $('span.crm-button-type-cancel').hide();
  });

  $("input.cancelInProgress").click(function() {
    if (CRM.vars.tsys.ips[$('select#device_id').val()].ip.length > 0) {
      var $ip = CRM.vars.tsys.ips[$('select#device_id').val()].ip;
      $.get("http://" + $ip + ":8080/v1/pos?Action=Cancel&Format=JSON", function(data) {
        console.log(data);
      });
    }
  });
});
