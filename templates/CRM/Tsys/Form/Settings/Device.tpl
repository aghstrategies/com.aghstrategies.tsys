<div class="help">
  <p>
    {ts}This form is to enter information about a specific TSYS Device.{/ts}
  </p>
</div>

<div class="crm-section">
  <div class="label">{$form.devicename.label}</div>
  <div class="content">{$form.devicename.html}</div>
  <div class="clear"></div>
</div>
<div class="crm-section">
  <div class="label">
    {$form.ip.label}
    {help id="id-ip" file="CRM/Tsys/Form/Settings/Device.hlp"}
  </div>
  <div class="content">{$form.ip.html}</div>
  <div class="clear"></div>
</div>
<div class="crm-section">
  <div class="label">
    {$form.terminalid.label}
    {help id="id-terminalid" file="CRM/Tsys/Form/Settings/Device.hlp"}
  </div>
  <div class="content">{$form.terminalid.html}</div>
  <div class="clear"></div>
</div>
<div class="crm-section">
  <div class="label">{$form.processorid.label}</div>
  <div class="content">{$form.processorid.html}</div>
  <div class="clear"></div>
</div>
<div class="crm-section">
  <div class="label">{$form.is_enabled.label}</div>
  <div class="content">{$form.is_enabled.html}</div>
  <div class="clear"></div>
</div>

{* FOOTER *}
<div class="crm-submit-buttons">
{include file="CRM/common/formButtons.tpl" location="bottom"}
</div>
