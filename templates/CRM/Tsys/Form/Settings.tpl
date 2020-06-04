{*
 +--------------------------------------------------------------------+
 | Copyright CiviCRM LLC. All rights reserved.                        |
 |                                                                    |
 | This work is published under the GNU AGPLv3 license with some      |
 | permitted exceptions and without any warranty. For full license    |
 | and copyright information, see https://civicrm.org/licensing       |
 +--------------------------------------------------------------------+
*}

<h2>TSYS Devices</h2>
<div class="help">
    {ts}Configure TSYS Devices in this section{/ts}
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

        <div class="action-link">
          {crmButton p="civicrm/tsyssettings/device" q="action=add&reset=1" id="newTSYSDevice" icon="plus-circle"}{ts}Add TSYS Device{/ts}{/crmButton}
        </div>

      </div>
</div>

{/if}
