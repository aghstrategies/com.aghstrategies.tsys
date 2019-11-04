/**
 * JS Integration between CiviCRM & Tsys.
 */
CRM.$(function($) {
  debugging("civicrm_tsys loaded, dom-ready function firing.");

  if (window.civicrmTsysHandleReload) {
    // Call existing instance of this, instead of making new one.

    debugging("calling existing civicrmTsysHandleReload.");
    window.civicrmTsysHandleReload();
    return;
  }

  // On initial load...
  var tsys;
  var card;
  var form;
  var submitButton;
  var tsysLoading = false;

  // Make sure data-cayan attributes for expiration fields
  // because cannot do it using quickform
  function markExpirationFields() {
    $('select#credit_card_exp_date_M').attr('data-cayan', 'expirationmonth');
    $('select#credit_card_exp_date_Y').attr('data-cayan', 'expirationyear');
    debugging('Expiration month set');
  }

  markExpirationFields();
  // Disable the browser "Leave Page Alert" which is triggered because we mess with the form submit function.
  window.onbeforeunload = null;

  /**
   * This function boots the UI.
   */
  window.civicrmTsysHandleReload = function() {
    debugging('civicrmTsysHandleReload');

    markExpirationFields();
    debugging('checkAndLoad from document.ready');
    checkAndLoad();

    // Load Tsys onto the form.
    var cardElement = document.getElementById('payment-token');
    if ((typeof cardElement !== 'undefined') && (cardElement)) {
      if (!cardElement.children.length) {
        debugging('checkAndLoad from document.ready');
        checkAndLoad();
      }
    }
  };
  // On initial run we need to call this now.
  window.civicrmTsysHandleReload();

  function successHandler(tokenResponse) {
    debugging(tokenResponse + ': success - submitting form');
    console.log(tokenResponse);
    // Insert the token ID into the form so it gets submitted to the server
    // var hiddenInput = document.createElement('input');
    // hiddenInput.setAttribute('type', 'hidden');
    // hiddenInput.setAttribute('name', 'payment_token');
    // hiddenInput.setAttribute('id', 'payment_token');
    // hiddenInput.setAttribute('value', tokenResponse.token);
    // form.appendChild(hiddenInput);

    form.find('input#payment_token').val(tokenResponse.token);

    // Submit the form
    form.submit();
  }

  // Response from tsys.createToken.
  function tsysFailureResponseHandler(tokenResponse) {
    $form = getBillingForm();
    $submit = getBillingSubmit();

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

  function nonTsysSubmit() {
    // Disable the submit button to prevent repeated clicks
    submitButton.setAttribute('disabled', true);
    return form.submit();
  }

  function displayError(result) {
    // Display error.message in your UI.
    debugging('error: ' + result.error.message);
    // Inform the user if there was an error
    var errorElement = document.getElementById('card-errors');
    errorElement.style.display = 'block';
    errorElement.textContent = result.error.message;
    document.querySelector('#billing-payment-block').scrollIntoView();
    window.scrollBy(0, -50);
    form.dataset.submitted = false;
    submitButton.removeAttribute('disabled');
  }

  function handleCardPayment() {
    debugging('handle card payment');
    CayanCheckoutPlus.createPaymentToken({
      success: successHandler,
      error: tsysFailureResponseHandler,
    });

    // tsys.createPaymentMethod('card', card).then(function (result) {
    //   if (result.error) {
    //     // Show error in payment form
    //     displayError(result);
    //   }
    //   else {
    //     if (getIsRecur() === true) {
    //       // Submit the form, if we need to do 3dsecure etc. we do it at the end (thankyou page) once subscription etc has been created
    //       successHandler('paymentMethodID', result.paymentMethod);
    //     }
    //     else {
    //       // Send paymentMethod.id to server
    //       var url = CRM.url('civicrm/tsys/confirm-payment');
    //       $.post(url, {
    //         payment_method_id: result.paymentMethod.id,
    //         amount: getTotalAmount(),
    //         currency: CRM.vars.tsys.currency,
    //         id: CRM.vars.tsys.id,
    //         description: document.title,
    //       }).then(function (result) {
    //         // Handle server response (see Step 3)
    //         handleServerResponse(result);
    //       });
    //     }
    //   }
    // });
  }

  // function handleServerResponse(result) {
  //   debugging('handleServerResponse');
  //   if (result.error) {
  //     // Show error from server on payment form
  //     displayError(result);
  //   } else if (result.requires_action) {
  //     // Use Tsys.js to handle required card action
  //     handleAction(result);
  //   } else {
  //     // All good, we can submit the form
  //     successHandler('paymentIntentID', result.paymentIntent);
  //   }
  // }

  function handleAction(response) {
    tsys.handleCardAction(response.payment_intent_client_secret)
      .then(function(result) {
        if (result.error) {
          // Show error in payment form
          displayError(result);
        } else {
          // The card action has been handled
          // The PaymentIntent can be confirmed again on the server
          successHandler('paymentIntentID', result.paymentIntent);
        }
      });
  }

  // Re-prep form when we've loaded a new payproc
  $(document).ajaxComplete(function(event, xhr, settings) {
    // /civicrm/payment/form? occurs when a payproc is selected on page
    // /civicrm/contact/view/participant occurs when payproc is first loaded on event credit card payment
    // On wordpress these are urlencoded
    if ((settings.url.match("civicrm(\/|%2F)payment(\/|%2F)form") !== null) ||
      (settings.url.match("civicrm(\/|\%2F)contact(\/|\%2F)view(\/|\%2F)participant") !== null)) {

      // See if there is a payment processor selector on this form
      // (e.g. an offline credit card contribution page).
      if (typeof CRM.vars.tsys === 'undefined') {
        return;
      }
      var paymentProcessorID = getPaymentProcessorSelectorValue();
      if (paymentProcessorID !== null) {
        // There is. Check if the selected payment processor is different
        // from the one we think we should be using.
        if (paymentProcessorID !== parseInt(CRM.vars.tsys.id)) {
          debugging('payment processor changed to id: ' + paymentProcessorID);
          if (paymentProcessorID === 0) {
            // Don't bother executing anything below - this is a manual / paylater
            return notTsys();
          }
          // It is! See if the new payment processor is also a Tsys Payment processor.
          // (we don't want to update the tsys pub key with a value from another payment processor).
          // Now, see if the new payment processor id is a tsys payment processor.
          CRM.api3('PaymentProcessor', 'getvalue', {
            "return": "user_name",
            "id": paymentProcessorID,
            "payment_processor_type_id": CRM.vars.tsys.paymentProcessorTypeID,
          }).done(function(result) {
            var pub_key = result.result;
            if (pub_key) {
              // It is a tsys payment processor, so update the key.
              debugging("Setting new tsys key to: " + pub_key);
              CRM.vars.tsys.publishableKey = pub_key;
            }
            else {
              return notTsys();
            }
            // Now reload the billing block.
            debugging('checkAndLoad from ajaxComplete');
            checkAndLoad();
          });
        }
      }
    }
  });

  function notTsys() {
    debugging("New payment processor is not Tsys, clearing CRM.vars.tsys");
    if ((typeof card !== 'undefined') && (card)) {
      debugging("destroying card element");
      card.destroy();
      card = undefined;
    }
    delete(CRM.vars.tsys);
  }

  function checkAndLoad() {
    if (typeof CRM.vars.tsys === 'undefined') {
      debugging('CRM.vars.tsys not defined! Not a Tsys processor?');
      return;
    }

    if (typeof Tsys === 'undefined') {
      if (tsysLoading) {
        return;
      }
      tsysLoading = true;
      debugging('Tsys.js is not loaded!');

      $.getScript('https://ecommerce.merchantware.net/v1/CayanCheckoutPlus.js', function () {
        debugging("Script loaded and executed.");
        tsysLoading = false;
        loadTsysBillingBlock();
      });
    }
    else {
      loadTsysBillingBlock();
    }
  }

  function loadTsysBillingBlock() {
    debugging('loadTsysBillingBlock');


    // Get api key
    if (typeof CRM.vars.tsys.id === 'undefined') {
      debugging('No payment processor id found');
    } else if (typeof CRM.vars.tsys.allApiKeys === 'undefined') {
      debugging('No payment processors array found');
    } else if (CayanCheckoutPlus === 'undefined') {
      debugging('No CayanCheckoutPlus');
    } else {
      if (CRM.vars.tsys.allApiKeys[CRM.vars.tsys.id]) {
        // Setup tsys.Js
        debugging(CRM.vars.tsys.allApiKeys[CRM.vars.tsys.id]);
        CayanCheckoutPlus.setWebApiKey(CRM.vars.tsys.allApiKeys[CRM.vars.tsys.id]);
      } else {
        debugging('current payment processor web api key not found');
      }
    }

    // if (typeof tsys === 'undefined') {
    //   tsys = Tsys(CRM.vars.tsys.publishableKey);
    // }
    // var elements = tsys.elements();

    // var style = {
    //   base: {
    //     fontSize: '20px',
    //   },
    // };

    // Create an instance of the card Element.
    // card = elements.create('card', {style: style});
    // card.mount('#card-element');
    // debugging("created new card element", card);
    //
    // // Hide the CiviCRM postcode field so it will still be submitted but will contain the value set in the tsys card-element.
    // document.getElementsByClassName('billing_postal_code-' + CRM.vars.tsys.billingAddressID + '-section')[0].setAttribute('hidden', true);
    // card.addEventListener('change', function(event) {
    //   updateFormElementsFromCreditCardDetails(event);
    // });

    // Get the form containing payment details
    form = getBillingForm();
    if (typeof form.length === 'undefined' || form.length === 0) {
      debugging('No billing form!');
      return;
    }
    submitButton = getBillingSubmit();

    // If another submit button on the form is pressed (eg. apply discount)
    //  add a flag that we can set to stop payment submission
    form.dataset.submitdontprocess = false;

    // Find submit buttons which should not submit payment
    var nonPaymentSubmitButtons = form.querySelectorAll('[type="submit"][formnovalidate="1"], ' +
      '[type="submit"][formnovalidate="formnovalidate"], ' +
      '[type="submit"].cancel, ' +
      '[type="submit"].webform-previous'), i;
    for (i = 0; i < nonPaymentSubmitButtons.length; ++i) {
      nonPaymentSubmitButtons[i].addEventListener('click', submitDontProcess());
    }

    function submitDontProcess() {
      debugging('adding submitdontprocess');
      form.dataset.submitdontprocess = true;
    }

    submitButton.addEventListener('click', submitButtonClick);
     console.log(submitButton);
    function submitButtonClick(event) {
      if (form.dataset.submitted === true) {
        return;
      }
      form.dataset.submitted = true;
      // Take over the click function of the form.
      if (typeof CRM.vars.tsys === 'undefined') {
        debugging('hi');
        // Submit the form
        return nonTsysSubmit();
      }
      debugging('clearing submitdontprocess');
      form.dataset.submitdontprocess = false;

      // Run through our own submit, that executes Tsys submission if
      // appropriate for this submit.
      console.log('submit?')
      return submit(event);
    }

    // Remove the onclick attribute added by CiviCRM.
    submitButton.removeAttribute('onclick');

    addSupportForCiviDiscount();

    // For CiviCRM Webforms.
    // if (getIsDrupalWebform()) {
    //   // We need the action field for back/submit to work and redirect properly after submission
    //
    //   $('[type=submit]').click(function() {
    //     addDrupalWebformActionElement(this.value);
    //   });
    //   // If enter pressed, use our submit function
    //   form.addEventListener('keydown', function (e) {
    //     if (e.keyCode === 13) {
    //       addDrupalWebformActionElement(this.value);
    //       submit(event);
    //     }
    //   });
    //
    //   $('#billingcheckbox:input').hide();
    //   $('label[for="billingcheckbox"]').hide();
    // }

    function submit(event) {
      event.preventDefault();
      debugging('submit handler');

      if ($(form).valid() === false) {
        debugging('Form not valid');
        return false;
      }

      if (typeof CRM.vars.tsys === 'undefined') {
        debugging('Submitting - not a tsys processor');
        return true;
      }

      if (form.dataset.submitted === true) {
        debugging('form already submitted');
        return false;
      }

      var tsysProcessorId = parseInt(CRM.vars.tsys.id);
      var chosenProcessorId = null;

      // Handle multiple payment options and Tsys not being chosen.
      // @fixme this needs refactoring as some is not relevant anymore (with tsys 6.0)
      if (getIsDrupalWebform()) {
        // this element may or may not exist on the webform, but we are dealing with a single (tsys) processor enabled.
        if (!$('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]').length) {
          chosenProcessorId = tsysProcessorId;
        } else {
          chosenProcessorId = parseInt(form.querySelector('input[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]:checked').value);
        }
      }
      else {
        // Most forms have payment_processor-section but event registration has credit_card_info-section
        if ((form.querySelector(".crm-section.payment_processor-section") !== null) ||
          (form.querySelector(".crm-section.credit_card_info-section") !== null)) {
          tsysProcessorId = CRM.vars.tsys.id;
          if (form.querySelector('input[name="payment_processor_id"]:checked') !== null) {
            chosenProcessorId = parseInt(form.querySelector('input[name="payment_processor_id"]:checked').value);
          }
        }
      }

      // If any of these are true, we are not using the tsys processor:
      // - Is the selected processor ID pay later (0)
      // - Is the Tsys processor ID defined?
      // - Is selected processor ID and tsys ID undefined? If we only have tsys ID, then there is only one (tsys) processor on the page
      if ((chosenProcessorId === 0) || (tsysProcessorId === null) ||
        ((chosenProcessorId === null) && (tsysProcessorId === null))) {
        debugging('Not a Tsys transaction, or pay-later');
        return nonTsysSubmit();
      }
      else {
        debugging('Tsys is the selected payprocessor');
      }

      // Don't handle submits generated by non-tsys processors
      if (typeof CRM.vars.tsys.publishableKey === 'undefined') {
        debugging('submit missing tsys-pub-key element or value');
        return true;
      }
      // Don't handle submits generated by the CiviDiscount button.
      if (form.dataset.submitdontprocess === true) {
        debugging('non-payment submit detected - not submitting payment');
        return true;
      }

      if (getIsDrupalWebform()) {
        // If we have selected Tsys but amount is 0 we don't submit via Tsys
        if ($('#billing-payment-block').is(':hidden')) {
          debugging('no payment processor on webform');
          return true;
        }

        // If we have more than one processor (user-select) then we have a set of radio buttons:
        var $processorFields = $('[name="submitted[civicrm_1_contribution_1_contribution_payment_processor_id]"]');
        if ($processorFields.length) {
          if ($processorFields.filter(':checked').val() === '0' || $processorFields.filter(':checked').val() === 0) {
            debugging('no payment processor selected');
            return true;
          }
        }
      }

      var totalFee = getTotalAmount();
      if (totalFee == '0') {
        debugging("Total amount is 0");
        return nonTsysSubmit();
      }

      // Lock to prevent multiple submissions
      if (form.dataset.submitted === true) {
        // Previously submitted - don't submit again
        alert('Form already submitted. Please wait.');
        return false;
      } else {
        // Mark it so that the next submit can be ignored
        form.dataset.submitted = true;
      }

      // Disable the submit button to prevent repeated clicks
      submitButton.setAttribute('disabled', true);

      // Create a token when the form is submitted.
      handleCardPayment();

      return true;
    }
  }

  function getIsDrupalWebform() {
    // form class for drupal webform: webform-client-form (drupal 7); webform-submission-form (drupal 8)
    if (form !== null) {
      return form.classList.contains('webform-client-form') || form.classList.contains('webform-submission-form');
    }
    return false;
  }

  function getBillingForm() {
    // If we have a tsys billing form on the page
    var billingFormID = $('div#payment-token').closest('form').prop('id');
    if ((typeof billingFormID === 'undefined') || (!billingFormID.length)) {
      // If we have multiple payment processors to select and tsys is not currently loaded
      billingFormID = $('input[name=hidden_processor]').closest('form').prop('id');
    }
    // We have to use document.getElementById here so we have the right elementtype for appendChild()
    return document.getElementById(billingFormID);
  }

  function getBillingSubmit() {
    var submit = null;
    if (getIsDrupalWebform()) {
      submit = form.querySelector('[type="submit"].webform-submit');
      if (!submit) {
        // drupal 8 webform
        submit = form.querySelector('[type="submit"].webform-button--submit');
      }
    }
    else {
      submit = form.querySelector('[type="submit"].validate');
    }
    return submit;
  }

  function getTotalAmount() {
    var totalFee = null;

    if ((document.getElementById('additional_participants') !== null) &&
       (document.getElementById('additional_participants').value.length !== 0)) {
      debugging('Cannot setup paymentIntent because we don\'t know the final price');
      return totalFee;
    }
    if (typeof calculateTotalFee == 'function') {
      // This is ONLY triggered in the following circumstances on a CiviCRM contribution page:
      // - With a priceset that allows a 0 amount to be selected.
      // - When Tsys is the ONLY payment processor configured on the page.
      totalFee = calculateTotalFee();
    }
    else if (getIsDrupalWebform()) {
      // This is how webform civicrm calculates the amount in webform_civicrm_payment.js
      $('.line-item:visible', '#wf-crm-billing-items').each(function() {
        totalFee += parseFloat($(this).data('amount'));
      });
    }
    else if (document.getElementById('total_amount')) {
      // The input#total_amount field exists on backend contribution forms
      totalFee = document.getElementById('total_amount').value;
    }
    return totalFee;
  }

  function getIsRecur() {
    // Auto-renew contributions
    if (document.getElementById('is_recur') !== null) {
      if (document.getElementById('is_recur').type == 'hidden') {
        return document.getElementById('is_recur').value == 1;
      }
      return Boolean(document.getElementById('is_recur').checked);
    }
    // Auto-renew memberships
    if (document.getElementById('auto_renew') !== null) {
      if (document.getElementById('auto_renew').type == 'hidden') {
        return document.getElementById('auto_renew').value == 1;
      }
      return Boolean(document.getElementById('auto_renew').checked);
    }
    return false;
  }

  // function updateFormElementsFromCreditCardDetails(event) {
  //   if (!event.complete) {
  //     return;
  //   }
  //   document.getElementById('billing_postal_code-' + CRM.vars.tsys.billingAddressID).value = event.value.postalCode;
  // }

  function addSupportForCiviDiscount() {
    // Add a keypress handler to set flag if enter is pressed
    cividiscountElements = form.querySelectorAll('input#discountcode');
    var cividiscountHandleKeydown = function(e) {
        if (e.keyCode === 13) {
          e.preventDefault();
          debugging('adding submitdontprocess');
          form.dataset.submitdontprocess = true;
        }
    };

    for (i = 0; i < cividiscountElements.length; ++i) {
      cividiscountElements[i].addEventListener('keydown', cividiscountHandleKeydown);
    }
  }

  function debugging(errorCode) {
    // Uncomment the following to debug unexpected returns.
    if ((typeof(CRM.vars.tsys) === 'undefined') || (Boolean(CRM.vars.tsys.jsDebug) === true)) {
      console.log(new Date().toISOString() + ' civicrm_tsys.js: ' + errorCode);
    }
  }

  function addDrupalWebformActionElement(submitAction) {
    var hiddenInput = null;
    if (document.getElementById('action') !== null) {
      hiddenInput = document.getElementById('action');
    }
    else {
      hiddenInput = document.createElement('input');
    }
    hiddenInput.setAttribute('type', 'hidden');
    hiddenInput.setAttribute('name', 'op');
    hiddenInput.setAttribute('id', 'action');
    hiddenInput.setAttribute('value', submitAction);
    form.appendChild(hiddenInput);
  }

  /**
   * Get the selected payment processor on the form
   * @returns int
   */
  function getPaymentProcessorSelectorValue() {
    if ((typeof form === 'undefined') || (!form)) {
      form = getBillingForm();
      if (!form) {
        return null;
      }
    }
    var paymentProcessorSelected = form.querySelector('input[name="payment_processor_id"]:checked');
    if (paymentProcessorSelected !== null) {
      return parseInt(paymentProcessorSelected.value);
    }
    return null;
  }

});
