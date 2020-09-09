CRM.$(function ($) {

  console.log(CRM.vars.tsys.ip);

  $(document).ready(function () {

    if (window.location.protocol == 'https:') {
      var $testUrl = "https://" + CRM.vars.tsys.ip + ":8443/v1/pos?Action=Status&Format=XML";
    }
    else {
      var $testUrl = "http://" +   CRM.vars.tsys.ip + ":8080/v2/pos?Action=Status&Format=XML";
    }
    console.log($testUrl);
    $.ajax({
      url: $testUrl,
      type: 'get',
      timeout: 1000,
    }).done(function(data) {
      CRM.alert('Connection Successful',
        'Test',
        'info',
        []
      );
    }).fail(function (xhr,status,error) {
      CRM.alert(status,
        error,
        'error',
        []
      );
    });

  });



});
