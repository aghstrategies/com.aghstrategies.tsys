{*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
*}

<h2>Devices</h2>
<div class="help">
    {ts}Configure Devices in this section. Genius offers a variety of Devices to accept credit card payments for more information visit: <a href="https://www.tsys.com/solutions/products-services/merchant/genius/">https://www.tsys.com/solutions/products-services/merchant/genius/</a> {/ts}
</div>


<div class="crm-content-block crm-block">
{if $devices}
<div id="ltype">
        {* handle enable/disable actions*}
        {* {include file="CRM/common/enableDisableApi.tpl"} *}
        <table class="selector row-highlight">
        <tr class="columnheader">
            <th>{ts}ID{/ts}</th>
            <th>{ts}Device Name{/ts}</th>
            <th>{ts}Payment Processor{/ts}</th>
            <th>{ts}IP Address{/ts}</th>
            <th>{ts}Terminal ID{/ts}</th>
            <th></th>
        </tr>
        {foreach from=$devices item=device}
        <tr id="payment_processor-{$device.id}" class="crm-entity {cycle values="odd-row,even-row"} {$device.class}">
            <td class="crmf-id center">{$device.id}</td>
            <td class="crmf-test_id">{$device.devicename}</td>
            <td class="crmf-name">{$device.processorid}</td>
            <td class="crmf-payment_processor_type">{$device.ip}</td>
            <td class="crmf-description">{$device.terminalid}</td>
            <td>{$device.action|replace:'xx':$device.id}</td>
        </tr>
        {/foreach}
        </table>
      </div>
      {/if}
  <div class="action-link">
    {crmButton p="civicrm/tsyssettings/device" q="action=add&reset=1" id="newDevice" icon="plus-circle"}{ts}Add Device{/ts}{/crmButton}
  </div>
</div>
