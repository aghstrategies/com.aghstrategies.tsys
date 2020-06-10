{* HEADER *}

{foreach from=$elementNames item=elementName}
  <div class="crm-section">
    <div class="label">{$form.$elementName.label}</div>
    <div class="content">{$form.$elementName.html}</div>
    <div class="clear"></div>
  </div>
{/foreach}

<div class="crm-submit-buttons">
  <span class='crm-button crm-i-button transport'>
    <i class='crm-i fa-check'></i>
    <input crm-icon='fa-times' class='crm-form-submit transport crm-button' value='Submit Transaction'>
  </span>
  <span class='crm-button crm-i-button cancelInProgress'>
    <i class='crm-i fa-times'></i>
    <input crm-icon='fa-times' class='crm-form-submit cancelInProgress crm-button' value='Cancel In Progress Transaction'>
  </span>
  {include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
