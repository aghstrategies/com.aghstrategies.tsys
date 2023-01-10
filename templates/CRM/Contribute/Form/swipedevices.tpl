<div class="swipeButtons" style="display: inline-block;">
  {foreach from=$swipedevices item=device key=label name=url}
       <a class="open-inline-noreturn action-item crm-hover-button" href={$device.url}> » {ts}Submit payment via {/ts} {$device.label} device</a>
  {/foreach}
</div>
