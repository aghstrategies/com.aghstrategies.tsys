/**
 * @file
 * JS Integration between CiviCRM & Tysus
 */
(function($, CRM) {
  // Set data-cayan attributes for expiration fields because cannot do it using quickform
  $("select#credit_card_exp_date_M").attr('data-cayan', 'expirationmonth');
  $("select#credit_card_exp_date_Y").attr('data-cayan', 'expirationyear');

  // Set Web API KEY
  CayanCheckoutPlus.setWebApiKey(CRM.vars.tsys.api);

  function successCallback(tokenResponse) {
    // Populate a hidden field with the single-use token
    $('#payment_token').val(tokenResponse.token);
    // Validate Form
    continueWithFormSubmission();
  }

  function failureCallback(tokenResponse) {
    // foreach thru error responses
    $.each(tokenResponse, function(key, details) {
      // TODO send error messages to Civi
      // Failed validation because missing a required field ignore
      if (details.error_code == "REQUIRED") {
      }
      // Failed validation because field did not pass tsys validation
      if (details.error_code == "VALIDATION") {

      }
    });

    continueWithFormSubmission();
  }

  function getBillingForm() {
    // If we have a stripe billing form on the page
    var $billingForm = $('input#payment_token').closest('form');

    if (!$billingForm.length) {
      // If we have multiple payment processors to select and stripe is not currently loaded
      $billingForm = $('input[name=hidden_processor]').closest('form');
    }
    return $billingForm;
  }

  function continueWithFormSubmission() {
    // Disable unload event handler
    window.onbeforeunload = null;
    $form = getBillingForm();
    $submit = $form.find('[type="submit"].validate');

    // Restore any onclickAction that was removed.
    $submit.attr('onclick', onclickAction);

    // This triggers submit without generating a submit event (so we don't run submit handler again)
    $form.get(0).submit();
  }

  // Prepare the form.
  var onclickAction = null;
  $(document).ready(function () {

    // Disable the browser "Leave Page Alert" which is triggered because we mess with the form submit function.
    var $submit = $('[type="submit"].validate');

    // Store and remove any onclick Action currently assigned to the form.
    // We will re-add it if the transaction goes through.
    onclickAction = $submit.attr('onclick');
    $submit.removeAttr('onclick');

    $('input.crm-form-submit').click(function () {
        // Prevent the user from double-clicking
        $(this).prop('disabled', true);
        // Create the payment token
        CayanCheckoutPlus.createPaymentToken({
            success: successCallback,
            error: failureCallback
        });
    });
});

}(cj, CRM));
