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
    console.log(tokenResponse);
    // Populate a hidden field with the single-use token
    $("[name='payment_token']").val(tokenResponse.token);

    // Submit the form
    $('input.crm-form-submit').submit();
}

function failureCallback(tokenResponse) {
  console.log(tokenResponse);

}

  $(document).ready(function () {
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
