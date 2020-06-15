CRM.$(function ($) {

  $("input.cancelInProgress").click(function() {
    if (CRM.vars.tsys.ips[$('select#device_id').val()].ip.length > 0) {
      var $ip = CRM.vars.tsys.ips[$('select#device_id').val()].ip;
      $.get("http://" + $ip + ":8080/v1/pos?Action=Cancel&Format=JSON", function(data) {
        if (data.Status == "Denied") {
          CRM.alert(data.ResponseMessage + " click 'Cancel In Progress Transaction' button again", data.Status, 'info', []);
        }
        if (data.Status == "Failed") {
          CRM.alert(data.ResponseMessage, data.Status, 'error', []);
        }
      });
    }
  });
});
