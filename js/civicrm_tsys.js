/**
 * @file
 * JS Integration between CiviCRM & tsys.
 */
CRM.$(function ($) {
  var onclickAction = null;

  $.getScript('https://ecommerce.merchantware.net/v1/CayanCheckoutPlus.js', function () {
     debugging("Script loaded and executed.");
   });

  // Make sure data-cayan attributes for expiration fields
  // because cannot do it using quickform
  function markExpirationFields() {
    $('select#credit_card_exp_date_M').attr('data-cayan', 'expirationmonth');
    $('select#credit_card_exp_date_Y').attr('data-cayan', 'expirationyear');
    debugging('Expiration month set');
  }

  markExpirationFields();

  // Response from tsys.createToken.
  function tsysSuccessResponseHandler(tokenResponse) {
    $form = getBillingForm();
    $submit = getBillingSubmit($form);
    // Update form with the token & submit.
    $form.find('input#payment_token').val(tokenResponse.token);

    // Disable unload event handler
    window.onbeforeunload = null;

    // Restore any onclickAction that was removed.
    $submit.attr('onclick', onclickAction);

    // This triggers submit without generating a submit event (so we don't run submit handler again)
    $form.get(0).submit();

  }

  // Response from tsys.createToken.
  function tsysFailureResponseHandler(tokenResponse) {
    $form = getBillingForm();
    $submit = getBillingSubmit($form);

    $('html, body').animate({ scrollTop: 0 }, 300);

    // Show the errors on the form.
    if ($('.messages.crm-error.tsys-message').length > 0) {
      $('.messages.crm-error.tsys-message').slideUp();
      $('.messages.crm-error.tsys-message:first').remove();
    }

    // foreach thru error responses
    $.each(tokenResponse, function (key, details) {
      $form.prepend(
        '<div class="messages alert alert-block alert-danger error crm-error tsys-message">'
        + '<strong>Payment Error Response:</strong>'
        + '<ul id="errorList">'
        + '<li>' + details.error_code + ': ' + details.reason + '</li>'
        + '</ul>'
        + '</div>');
    });

    $form.data('submitted', false);
    $submit.prop('disabled', false);
  }

  // Prepare the form.
  $(document).ready(function () {
    // Disable the browser "Leave Page Alert" which is triggered
    // because we mess with the form submit function.
    window.onbeforeunload = null;

    // Load tsys onto the form.
    loadtsysBillingBlock();
    $form = getBillingForm();
    $submit = getBillingSubmit($form);

    // Store and remove any onclick Action currently assigned to the form.
    // We will re-add it if the transaction goes through.
    onclickAction = $submit.attr('onclick');
    $submit.removeAttr('onclick');
  });

  // Re-prep form when we've loaded a new payproc
  $(document).ajaxComplete(function (event, xhr, settings) {
    // Make sure expiration fields are marked so cayan.js can find them
    markExpirationFields();

    // /civicrm/payment/form? occurs when a payproc is selected on page
    if ((settings.url.match("civicrm(\/|%2F)payment(\/|%2F)form") !== null) ||
      (settings.url.match("civicrm(\/|\%2F)contact(\/|\%2F)view(\/|\%2F)participant") !== null) ||
      (settings.url.match("civicrm(\/|\%2F)custom") !== null)) {
      // See if there is a payment processor selector on this form
      // (e.g. an offline credit card contribution page).
      if ($('#payment_processor_id').length > 0) {
        debugging('payment processor changed to id: ' + $('#payment_processor_id'));

        // There is. Check if the selected payment processor is different
        // from the one we think we should be using.
        var ppid = $('#payment_processor_id').val();

        if (ppid != $('#tsys-id').val()) {
          debugging('payment processor changed to id: ' + ppid);
          // see if the new payment processor id is a tsys payment processor.
          if (CRM.vars.tsys.allApiKeys[ppid]) {
            // It is a tsys payment processor, so update the key.
            debugging('Setting new tsys key to: ' + CRM.vars.tsys.allApiKeys[ppid]);
            CayanCheckoutPlus.setWebApiKey(CRM.vars.tsys.allApiKeys[ppid]);
          } else {
            debugging('New payment processor is not tsys');
          }

          // Now reload the billing block.
          loadtsysBillingBlock();
        }
      }

      loadtsysBillingBlock();
    }
  });

  function loadtsysBillingBlock() {

    // Get api key
    if (typeof CRM.vars.tsys.pp === 'undefined') {
      debugging('No payment processor id found');
    } else if (typeof CRM.vars.tsys.allApiKeys === 'undefined') {
      debugging('No payment processors array found');
    } else if (CayanCheckoutPlus === 'undefined') {
      debugging('No CayanCheckoutPlus');
    } else {
      if (CRM.vars.tsys.allApiKeys[CRM.vars.tsys.pp]) {
        // Setup tsys.Js
        CayanCheckoutPlus.setWebApiKey(CRM.vars.tsys.allApiKeys[CRM.vars.tsys.pp]);
      } else {
        debugging('current payment processor web api key not found');
      }
    }

    // Get the form containing payment details
    $form = getBillingForm();
    if (!$form.length) {
      debugging('No billing form!');
      return;
    }

    $submit = getBillingSubmit($form);

    // If another submit button on the form is pressed (eg. apply discount)
    //  add a flag that we can set to stop payment submission
    $form.data('submit-dont-process', '0');

    // Find submit buttons which should not submit payment
    $form.find('[type="submit"][formnovalidate="1"], ' +
      '[type="submit"][formnovalidate="formnovalidate"], ' +
      '[type="submit"].cancel, ' +
      '[type="submit"].webform-previous').click(function () {
      debugging('adding submit-dont-process');
      $form.data('submit-dont-process', 1);
    });

    $submit.click(function (event) {
      // Take over the click function of the form.
      debugging('clearing submit-dont-process');
      $form.data('submit-dont-process', 0);

      // Run through our own submit, that executes tsys submission if
      // appropriate for this submit.
      var ret = submit(event);
      if (ret) {
        // True means it's not our form. We are bailing and not trying to
        // process tsys.
        // Restore any onclickAction that was removed.
        $form = getBillingForm();
        $submit = getBillingSubmit($form);
        $submit.attr('onclick', onclickAction);
        $form.get(0).submit();
        return true;
      }

      // Otherwise, this is a tsys submission - don't handle normally.
      // The code for completing the submission is all managed in the
      // tsys handler (tsysResponseHandler) which gets execute after
      // tsys finishes.
      return false;
    });

    // Add a keypress handler to set flag if enter is pressed
    $form.find('input#discountcode').keypress(function (e) {
      if (e.which === 13) {
        $form.data('submit-dont-process', 1);
      }
    });

    var isWebform = getIsWebform();

    // For CiviCRM Webforms.
    if (isWebform) {
      // We need the action field for back/submit to work and redirect properly after submission
      if (!($('#action').length)) {
        $form.append($('<input type="hidden" name="op" id="action" />'));
      }

      var $actions = $form.find('[type=submit]');
      $('[type=submit]').click(function () {
        $('#action').val(this.value);
      });

      // If enter pressed, use our submit function
      $form.keypress(function (event) {
        if (event.which === 13) {
          $('#action').val(this.value);
          submit(event);
        }
      });

      $('#billingcheckbox:input').hide();
      $('label[for="billingcheckbox"]').hide();
    }

    function submit(event) {
      event.preventDefault();
      debugging('submit handler');
      $form = getBillingForm();

      if ($form.data('submitted') === true) {
        debugging('form already submitted');
        return false;
      }

      var isWebform = getIsWebform();

      // Handle multiple payment options and tsys not being chosen.
      if (isWebform) {
        var tsysProcessorId;
        var chosenProcessorId;
        tsysProcessorId = $('#tsys-id').val();

        // this element may or may not exist on the webform, but we are
        // dealing with a single (tsys) processor enabled.
        if (!$(
          'input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]'
        ).length) {
          chosenProcessorId = tsysProcessorId;
        } else {
          chosenProcessorId = $form.find(
            'input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]:checked'
          ).val();
        }
      } else {
        // Most forms have payment_processor-section but event
        // registration has credit_card_info-section
        if (($form.find('.crm-section.payment_processor-section').length > 0)
        || ($form.find('.crm-section.credit_card_info-section').length > 0)) {
          // payment processor field is a select
          if ($form.find('select[name="payment_processor_id"]').length) {
            chosenProcessorId = $form.find('select[name="payment_processor_id"]').val();
          }

          // payment processor field is radios
          if ($form.find('input[name="payment_processor_id"]').length) {
            chosenProcessorId = $form.find('input[name="payment_processor_id"]:checked').val();
          }

          // Only one processor on the form so use that
          if ($form.find('input[name="payment_processor_id"][type="hidden"]').length) {
            chosenProcessorId = $form.find(
              'input[name="payment_processor_id"][type="hidden"]').val();
          }
        }
      }

      debugging(CRM.vars.tsys.allApiKeys);
      debugging(chosenProcessorId);

      // Make sure we are using a tsys processor
      if (CRM.vars.tsys.allApiKeys[chosenProcessorId]) {
        debugging('tsys is the selected payprocessor');
        CayanCheckoutPlus.setWebApiKey(CRM.vars.tsys.allApiKeys[chosenProcessorId]);
      } else {
        debugging('Not a tsys transaction, or pay-later');
        return true;
      }

      $form = getBillingForm();

      $submit = getBillingSubmit($form);

      if (isWebform) {
        // If we have selected tsys but amount is 0 we don't submit via tsys
        if ($('#billing-payment-block').is(':hidden')) {
          debugging('no payment processor on webform');
          return true;
        }

        // If we have more than one processor (user-select) then we have a set of radio buttons:
        var $processorFields = $(
          '[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]'
        );
        if ($processorFields.length) {
          if ($processorFields.filter(':checked').val() === '0' ||
          $processorFields.filter(':checked').val() === 0) {
            debugging('no payment processor selected');
            return true;
          }
        }
      }

      // This is ONLY triggered in the following circumstances on a CiviCRM contribution page:
      // - With a priceset that allows a 0 amount to be selected.
      // - When tsys is the ONLY payment processor configured on the page.
      if (typeof calculateTotalFee == 'function') {
        var totalFee = calculateTotalFee();
        if (totalFee == '0') {
          debugging('Total amount is 0');
          return true;
        }
      }

      // If there's no credit card field, no use in continuing (probably wrong
      // context anyway)
      if (!$form.find('#credit_card_number').length) {
        debugging('No credit card field');
        return true;
      }

      // Lock to prevent multiple submissions
      if ($form.data('submitted') === true) {
        // Previously submitted - don't submit again
        alert('Form already submitted. Please wait.');
        return false;
      } else {
        // Mark it so that the next submit can be ignored
        // ADDED requirement that form be valid
        if ($form.valid()) {
          $form.data('submitted', true);
        }
      }

      // Disable the submit button to prevent repeated clicks
      $submit.prop('disabled', true);

      compileCardHolder();

      CayanCheckoutPlus.createPaymentToken({
        success: tsysSuccessResponseHandler,
        error: tsysFailureResponseHandler,
      });
      return false;
    }
  }

  // AGH #20367 compile Card Holder based on first and last name
  function compileCardHolder() {
    // Check if there is a card holder field
    // if there is not create one
    if ($("input#card_holder_tsys").length) {
      $('input#card_holder_tsys').val('');
    } else {
      $('<input/>').attr({
        type: 'text',
        id: 'card_holder_tsys',
        name: 'card_holder_tsys',
        'data-cayan': 'cardholder',
        style: 'display:none;',
      }).appendTo('input#payment_token');
    }

    // Compile Card Holder Name
    var nameParts = [];
    var billingNameFields = [
      'billing_first_name',
      'billing_middle_name',
      'billing_last_name',
    ];
    $.each(billingNameFields, function(index, fieldName) {
      if ($('input#' + fieldName).val().length) {
        nameParts.push($('input#' + fieldName).val());
      }
    });

    // set value of the cardholder field to Card holder name
    // compiled from billing name fields
    $('input#card_holder_tsys').val(nameParts.join(' '));
  }

  function getIsWebform() {
    return $('.webform-client-form').length;
  }

  function getBillingForm() {
    // If we have a tsys billing form on the page
    var $billingForm = $('input#payment_token').closest('form');
    if (!$billingForm.length && getIsWebform()) {
      // If we are in a webform
      // TODO Can we distinguish that this is a webform w/ a payment in case
      // there's another webform in the sidebar?
      $billingForm = $('.webform-client-form');
    }

    if (!$billingForm.length) {
      // If we have multiple payment processors to select and tsys is not currently loaded
      $billingForm = $('input[name=hidden_processor]').closest('form');
    }

    return $billingForm;
  }

  function getBillingSubmit($form) {
    var isWebform = getIsWebform();

    if (isWebform) {
      $submit = $form.find('[type="submit"].webform-submit');
    } else {
      $submit = $form.find('[type="submit"].validate');
    }

    return $submit;
  }

  function debugging(errorCode) {
    // Uncomment the following to debug unexpected returns.
    // console.log(new Date().toISOString() + ' civicrm_tsys.js: ' + errorCode);
  }

});
