{*<!-- SamenWidgets – tasks and events whose time is up (overdue / due today) -->*}
{strip}
<div class="sw-due">
	<div class="sw-due-sum">
		<span class="sw-pill sw-red">{vtranslate('LBL_OVERDUE', 'SamenWidgets')}: <b>{$SW_DUE.overdue_count}</b></span>
		<span class="sw-pill sw-amber">{vtranslate('LBL_DUE_TODAY', 'SamenWidgets')}: <b>{$SW_DUE.today_count}</b></span>
	</div>
	{if $SW_DUE.count eq 0}
		<p class="sw-empty"><i class="fa fa-check-circle"></i> {vtranslate('LBL_NOTHING_DUE', 'SamenWidgets')}</p>
	{/if}
	<ul class="sw-due-list">
		{foreach item=A from=$SW_DUE.today}
			<li class="sw-today"><a href="index.php?module=Calendar&view=Detail&record={$A.id}"><i class="fa {if $A.type eq 'task'}fa-check-square-o{else}fa-calendar{/if}"></i> {$A.subject|escape:'html'}</a>
				<small>{vtranslate('LBL_TODAY', 'SamenWidgets')}{if $A.time} · <span dir="ltr">{$A.time}</span>{/if}{if $A.contact} · {$A.contact|escape:'html'}{/if}</small></li>
		{/foreach}
		{foreach item=A from=$SW_DUE.overdue}
			<li class="sw-overdue"><a href="index.php?module=Calendar&view=Detail&record={$A.id}"><i class="fa {if $A.type eq 'task'}fa-check-square-o{else}fa-calendar{/if}"></i> {$A.subject|escape:'html'}</a>
				<small><span class="sw-date" data-gdate="{$A.date}">{$A.date}</span>{if $A.time} · <span dir="ltr">{$A.time}</span>{/if}{if $A.contact} · {$A.contact|escape:'html'}{/if}</small></li>
		{/foreach}
	</ul>
	{if $SW_DUE.count gt 50}<p class="sw-more"><a href="index.php?module=Calendar&view=List">{vtranslate('LBL_SEE_ALL', 'SamenWidgets')}</a></p>{/if}
</div>
{/strip}
