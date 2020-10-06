CRM.$(function ($) {
  // JS to process a transaction via a Device
  $(document).ready(function () {
    // hide fields to save Responses
    $("input#tsys_initiate_response").parent().parent().hide();
    $("input#tsys_create_response").parent().parent().hide();
    $("input#contribution_id.device").parent().parent().hide();
  });

  // Function to ensure the required fields are populated before submit
  var validateForm = function() {
    // validate form
    var allData = 1;
    if ($('input#contribution_id.device').length == 1) {
      var fields = {
        amount: 'input#total_amount',
        device: 'select#device_id',
        contribution: 'input#contribution_id.device',
      };
    }
    else {
      var fields = {
        amount: 'input#total_amount',
        device: 'select#device_id',
        fintype: 'input#financial_type_id',
        contact: 'input[name="contact_id"]',
      };
    }
    $.each(fields, function(name, val) {
      if ($(val).val() == undefined || $(val).val() == '') {
        $(val).crmError(ts('required fields are missing'));
        allData = 0;
      }
      if (name == 'amount' && !$.isNumeric($(val).val())) {
        allData = 0;
        $(val).crmError(ts('must be numeric'));
      }
    });
    return allData;
  };

  // Compile Transport URL
  function compileTransportURL(type, transportkey) {
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
    if (type == 'report') {
      $urlParams = $urlParams + "&tk=" + transportkey;
    }
    return CRM.vars.tsys.transport + $urlParams;
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

  function ajaxError(xhr,status,error) {
    CRM.alert(status, 'error', error, []);
    resetButtons();
  }

  // reset buttons because transaction failed
  function resetButtons() {
    $(".cancelInProgress").hide();
    $("i.loadingIcon").hide();
    $('span.crm-button-type-cancel').show();
    $('span.crm-button-type-submit').show();
  }

  function sendInfoToTsys(e) {

    // Check that all required fields are populated
    var allData = validateForm();

    // If form is valid (all required fields are populated)
    if (allData == 1) {
      // prevent form submit until ajax calls are done
      e.preventDefault();

      // hide submit and cancel buttons and show loading icon/cancel in progress transaction button
      $(".cancelInProgress").show();
      $("i.loadingIcon").show();
      $('span.crm-button-type-cancel').hide();
      $('span.crm-button-type-submit').hide();

      // Test connection to device
      var $statusUrl = compileDeviceUrl('Status');
      $.ajax({
        url: $statusUrl,
        type: 'get',
        timeout: 7000,
      }).done( function() {
        // Compile Transport URL
        var $transportUrl = compileTransportURL('stage', '');
        $.ajax({
          url: $transportUrl,
          type: 'get',
          timeout: 60000,
        })
        .done(function(data) {

          var initiateResponse = JSON.stringify(data);
          $('input#tsys_initiate_response').val(initiateResponse);
          if (data.TransportKey.length > 0 && data.status == 'success' && CRM.vars.tsys.ips[$('select#device_id').val()].ip.length > 0) {
            $create = compileCreateTransactionURL(data);
            $.ajax({
              url: $create,
              type: 'get',
              timeout: 60000,
            })
            .done(function(responseCreate) {
              var createResponse = JSON.stringify(responseCreate);
              $('input#tsys_create_response').val(createResponse);
              $('input.validate').unbind('click').click();
            })
            .fail(function (xhr,status,error) {
              var $reportUrl = compileTransportURL('report', data.TransportKey);
              console.log($reportUrl);
              $.ajax({
                url: $reportUrl,
                type: 'get',
                timeout: 60000,
              })
              .done(function(response) {
                console.log(response);
                if (response.status == 'success') {
                  if (response.Body.DetailsByTransportKeyResponse.DetailsByTransportKeyResult.Status == "FAILED") {
                    CRM.alert(response.Body.DetailsByTransportKeyResponse.DetailsByTransportKeyResult.ErrorMessage,
                      response.Body.DetailsByTransportKeyResponse.DetailsByTransportKeyResult.Status,
                      'error',
                      []
                    );
                    resetButtons();
                  }
                  else if (response.Body.DetailsByTransportKeyResponse.DetailsByTransportKeyResult.Status == "APPROVED") {
                    var reportResponse = JSON.stringify(response.Body.DetailsByTransportKeyResponse.DetailsByTransportKeyResult);
                    $('input#tsys_create_response').val(reportResponse);
                    $('input.validate').unbind('click').click();
                  }
                }
                else {
                  CRM.alert("Failed to get transaction details", response.status, 'error', []);
                  resetButtons();
                }
              })
              .fail(ajaxError)
            });
          }
          else {
            CRM.alert("Transport Failed", data.status, 'error', []);
          }
        })
        .fail(ajaxError);
      })
      .fail(function(xhr,status,error) {
        ajaxError(xhr, 'Error connecting to device. <p>There are a variety of reasons this may be the case including but not limited to:</p><ul><li>You may need to install a <a href="https://docs.tsysmerchant.com/knowledge-base/faqs/how-do-i-install-the-genius-root-certificate">root certificate</a> for your browser.</li><li>The device settings may be incorrect.</li><li>Your Device must be on the same network as computer you are issuing the request from.</li></ul>', 'error')
      });
    }
  }

  $('input.validate').on('click', sendInfoToTsys);

  // If the cancel in progress transaction button is clicked, cancel the transaction
  $(".cancelInProgress").on('click', function() {
    var $cancelUrl = compileDeviceUrl('Cancel');
    $.ajax({
      url: $cancelUrl,
      type: 'get',
    }).done(cancelSuccess)
    .fail(ajaxError);
  });

  function compileDeviceUrl(action) {
    if (CRM.vars.tsys.ips[$('select#device_id').val()].ip.length > 0) {
      var $ip = CRM.vars.tsys.ips[$('select#device_id').val()].ip;
      var $url = "http://" + $ip + ":8080/v1/pos?Action=" + action + "&Format=JSON";

      // if https use https version of cancel url
      if (window.location.protocol == 'https:') {
        var $url = "https://" + $ip + ":8443/v1/pos?Action=" + action + "&Format=JSON";
      }
    }
    return $url;
  }

  function cancelSuccess(data) {
    if (data.Status == "Denied") {
      CRM.alert(data.ResponseMessage + " click 'Cancel In Progress Transaction' button again", data.Status, 'info', []);
    }
    if (data.Status == "Failed") {
      CRM.alert(data.ResponseMessage, data.Status, 'error', []);
    }
  }

});
