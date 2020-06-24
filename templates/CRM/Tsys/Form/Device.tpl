{* HEADER *}

{foreach from=$elementNames item=elementName}
  <div class="crm-section">
    <div class="label">{$form.$elementName.label}</div>
    <div class="content">{$form.$elementName.html}</div>
    <div class="clear"></div>
  </div>
{/foreach}

<div class="crm-submit-buttons">
  {include file="CRM/common/formButtons.tpl" location="bottom"}
  <span class='crm-button crm-i-button cancelInProgress'>
    <i class='crm-i fa-times'></i>
    <input type="button" id="cancelInProgress" crm-icon='fa-times' class='crm-form-submit cancelInProgress crm-button cancel' value='Cancel In Progress Transaction'>
  </span>
</div>
