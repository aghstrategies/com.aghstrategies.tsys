<div class="devices">
{if $newCredit}
  {foreach from=$devices item=device}
    <a accesskey="N" href={$device.url} class="button"><span><i class="crm-i fa-credit-card"></i> Submit Credit Card Contribution with {$device.label}</span></a>
  {/foreach}
{/if}
</div>