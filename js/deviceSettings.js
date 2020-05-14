CRM.$(function ($) {
  // Moves device settings above save button
  $('.deviceTable').insertBefore('.crm-submit-buttons:last');

  // Show Device Table ONLY if TSYS processor
  function checkProcessorType() {
    if ($("select#payment_processor_type_id option:selected").text() == "TSYS") {
      $('.deviceTable').show();
    }
    else {
      $('.deviceTable').hide();
    }
  }
  checkProcessorType();
});
