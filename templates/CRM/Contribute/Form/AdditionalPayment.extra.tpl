<div class="swipeButtons">
  {foreach from=$devices item=device key=label name=url}
       <a class="open-inline-noreturn action-item crm-hover-button" href={$device.url}> » {ts}submit swipe payment{/ts} {$device.label}</a>
  {/foreach}
</div>
