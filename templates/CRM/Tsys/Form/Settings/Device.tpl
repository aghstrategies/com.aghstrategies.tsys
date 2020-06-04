<div class="help">
  <p>
    {ts}This form is to enter information about a specific TSYS Device. To find the IP address of the device visit the <a href='https://docs.tsysmerchant.com/knowledge-base/faqs'>TSYS Knowledge Base FAQs</a>{/ts}
  </p>
</div>

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
