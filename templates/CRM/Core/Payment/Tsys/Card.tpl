{* https://civicrm.org/licensing *}

{* Manually create the CRM.vars.tsys here for drupal webform because \Civi::resources()->addVars() does not work in this context *}
{* {literal}
<script type="text/javascript">
  CRM.$(function($) {
    $(document).ready(function() {
      if (typeof CRM.vars.tsys === 'undefined') {
        var tsys = {{/literal}{foreach from=$tsysJSVars key=arrayKey item=arrayValue}{$arrayKey}:'{$arrayValue}',{/foreach}{literal}};
        CRM.vars.tsys = tsys;
      }
    });
  });
</script>
{/literal}
*}

{* Add the components required for a Stripe card element *}
<label for="payment-token"><legend>Credit or debit card</legend></label>
<div id="payment-token"></div>
{* Area for Stripe to report errors *}
<div id="payment-token-errors" role="alert" class="alert alert-danger"></div>
