CRM.$(function ($) {
  // Move Swipe buttons to line up with other links to add payments
  $('div.swipeButtons').appendTo('.action-link.css_right.crm-link-credit-card-mode');
  $('div.swipeButtons').insertAfter('.open-inline.action-item:last');
});
