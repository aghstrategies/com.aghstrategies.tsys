CRM.$(function ($) {
  // Test connection to Device
  $(document).ready(function () {
    if (window.location.protocol == 'https:') {
      var $testUrl = "https://" + CRM.vars.tsys.ip + ":8443/v1/pos?Action=Status&Format=XML";
    }
    else {
      var $testUrl = "http://" +   CRM.vars.tsys.ip + ":8080/v2/pos?Action=Status&Format=XML";
    }
    $.ajax({
      url: $testUrl,
      type: 'get',
      timeout: 1000,
    }).done(function(data) {
      CRM.alert('Connection to Device ID: ' + CRM.vars.tsys.id + ' Successful',
        '',
        'success',
        []
      );
    }).fail(function (xhr,status,error) {
      CRM.alert('<p>Connection Test to Device ID:' + CRM.vars.tsys.id + ' NOT Successful.</p><p>There are a variety of reasons this may be the case including but not limited to:</p><ul><li>You may need to install a <a href="https://docs.tsysmerchant.com/knowledge-base/faqs/how-do-i-install-the-genius-root-certificate">root certificate</a> for your browser.</li><li>The device settings may be incorrect.</li><li>Your Device must be on the same network as computer you are issuing the request from.</li></ul>',
        'Connection NOT Successful',
        'error',
        []
      );
    });
  });
});
