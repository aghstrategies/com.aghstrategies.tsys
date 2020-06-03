<div class="devices">
{if $newCredit}
  {foreach from=$devices item=device}
    <a target="_self" href={$device.url} class="button open-inline"><span><i class="crm-i fa-credit-card"></i> Submit Credit Card Contribution with {$device.label}</span></a>
  {/foreach}
{/if}
</div>
