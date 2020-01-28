{* HEADER *}

{* <div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="top"}
</div> *}

{* FIELD EXAMPLE: OPTION 1 (AUTOMATIC LAYOUT) *}
<div class='messages status no-popup'>Are you sure you would like to issue a refund for this payment?</div>
{foreach from=$elementNames item=elementName}
  <div class="crm-section">
    <div class="label">{$form.$elementName.label}</div>
    <div class="content">{$form.$elementName.html}</div>
    <div class="clear"></div>
  </div>
{/foreach}

{* FIELD EXAMPLE: OPTION 2 (MANUAL LAYOUT)

  <div>
    <span>{$form.favorite_color.label}</span>
    <span>{$form.favorite_color.html}</span>
  </div>

{* FOOTER *}
<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
