{* HEADER *}

{foreach from=$elementNames item=elementName}

  <div class="crm-section">
    <div class="label">{$form.$elementName.label}</div>
    <div class="content">{$form.$elementName.html}
      {if $elementName == 'total_amount'}
      <span class='status'>Balance Owed: ${$balance}</span>
      {/if}
    </div>
    <div class="clear"></div>
  </div>
{/foreach}

{include file="CRM/common/formButtons.tpl" location="bottom"}

  <i style="display: none;" class="loadingIcon crm-i fa-spinner fa-pulse fa-2x fa-fw"></i>
  <span style="display: none;" class='crm-button crm-i-button cancelInProgress'>
    <i class='crm-i fa-times'></i>
    <input type="button" id="cancelInProgress" crm-icon='fa-times' class='crm-form-submit cancelInProgress crm-button cancel' value='Cancel In Progress Transaction'>
  </span>
