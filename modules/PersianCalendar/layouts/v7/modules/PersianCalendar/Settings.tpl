{*<!-- PersianCalendar – Settings > Extensions > Persian calendar (admins) -->*}
{strip}
<div class="main-container clearfix">
<div class="content-area full-width samen-ext" id="persianCalendarSettings" data-module="PersianCalendar">
	<div class="samen-ext-head">
		<span class="samen-ext-logo" aria-hidden="true"><i class="fa fa-calendar" style="font-size:30px;color:#13606F;line-height:36px;display:block;text-align:center"></i></span>
		<div>
			<h3>{vtranslate('LBL_PC_TITLE', 'PersianCalendar')}</h3>
			<p class="samen-ext-sub">{vtranslate('LBL_PC_SUBTITLE', 'PersianCalendar')} <span class="samen-ext-ver" dir="ltr">v{$PC.version}</span></p>
		</div>
		<div class="samen-ext-actions">
			<button type="button" class="btn btn-primary" data-samen-action="users" data-done="{vtranslate('LBL_PC_DONE', 'PersianCalendar')}" data-confirm="{vtranslate('LBL_PC_USERS_CONFIRM', 'PersianCalendar')}"><i class="fa fa-users"></i>&nbsp;{vtranslate('LBL_PC_APPLY_USERS', 'PersianCalendar')}</button>
			<button type="button" class="btn btn-default" data-samen-action="apply" data-done="{vtranslate('LBL_PC_DONE', 'PersianCalendar')}"><i class="fa fa-refresh"></i>&nbsp;{vtranslate('LBL_PC_APPLY', 'PersianCalendar')}</button>
		</div>
	</div>

	<div class="samen-ext-grid">
		<section class="samen-ext-card">
			<h4>{vtranslate('LBL_PC_TODAY', 'PersianCalendar')}</h4>
			<div class="samen-ext-kpis">
				<div class="samen-ext-kpi"><small>{vtranslate('LBL_PC_SHOWN', 'PersianCalendar')}</small><span class="samen-ext-big">{$TODAY}</span></div>
				<div class="samen-ext-kpi"><small>{vtranslate('LBL_PC_STORED', 'PersianCalendar')}</small><span class="samen-ext-big pc-skip" dir="ltr">{$TODAY}</span></div>
			</div>
			<p class="samen-ext-note">{vtranslate('LBL_PC_TODAY_HELP', 'PersianCalendar')}</p>
		</section>

		<section class="samen-ext-card">
			<h4>{vtranslate('LBL_PC_STATUS', 'PersianCalendar')}</h4>
			<ul class="samen-ext-checks">
				<li class="{if $PC.links}ok{else}bad{/if}"><span>{vtranslate('LBL_PC_SCRIPTS', 'PersianCalendar')}</span><b>{if $PC.links}{vtranslate('LBL_PC_ACTIVE', 'PersianCalendar')}{else}{vtranslate('LBL_PC_MISSING', 'PersianCalendar')}{/if}</b></li>
				<li class="{if $PC.lang_ok}ok{else}bad{/if}"><span>{vtranslate('LBL_PC_LANG', 'PersianCalendar')}</span><b>{if $PC.lang_ok}{vtranslate('LBL_PC_ACTIVE', 'PersianCalendar')}{else}{vtranslate('LBL_PC_MISSING', 'PersianCalendar')}{/if}</b></li>
				<li class="{if $PC.default_language eq 'fa_ir'}ok{else}warn{/if}"><span>{vtranslate('LBL_PC_DEFAULT_LANG', 'PersianCalendar')}</span><b dir="ltr">{$PC.default_language|escape:'html'}</b></li>
				<li class="{if $PERSIAN_USERS eq count($USERS)}ok{else}warn{/if}"><span>{vtranslate('LBL_PC_USERS_OK', 'PersianCalendar')}</span><b>{$PERSIAN_USERS} / {count($USERS)}</b></li>
			</ul>
		</section>
	</div>

	<section class="samen-ext-card">
		<h4>{vtranslate('LBL_PC_USERS', 'PersianCalendar')}</h4>
		<div class="table-responsive">
		<table class="table samen-ext-table">
			<thead><tr>
				<th>{vtranslate('LBL_PC_USER', 'PersianCalendar')}</th><th>{vtranslate('LBL_PC_LANGUAGE', 'PersianCalendar')}</th>
				<th>{vtranslate('LBL_PC_WEEK_START', 'PersianCalendar')}</th><th>{vtranslate('LBL_PC_DATE_FORMAT', 'PersianCalendar')}</th>
				<th>{vtranslate('LBL_PC_TIMEZONE', 'PersianCalendar')}</th><th></th>
			</tr></thead>
			<tbody>
			{foreach item=U from=$USERS}
				<tr>
					<td><a href="index.php?module=Users&parent=Settings&view=Detail&record={$U.id}">{$U.full_name|escape:'html'}</a> <small class="text-muted" dir="ltr">({$U.user_name|escape:'html'})</small></td>
					<td dir="ltr">{$U.language|escape:'html'}</td>
					<td>{vtranslate('DAY_'|cat:$U.dayoftheweek, 'PersianCalendar')}</td>
					<td dir="ltr">{$U.date_format|escape:'html'}</td>
					<td dir="ltr">{$U.time_zone|escape:'html'}</td>
					<td>{if $U.persian}<span class="samen-ext-tag">{vtranslate('LBL_PC_PERSIAN', 'PersianCalendar')}</span>{else}<span class="samen-ext-tag off">{vtranslate('LBL_PC_NOT_PERSIAN', 'PersianCalendar')}</span>{/if}</td>
				</tr>
			{/foreach}
			</tbody>
		</table>
		</div>
		<p class="samen-ext-note">{vtranslate('LBL_PC_USERS_HELP', 'PersianCalendar')}</p>
	</section>
</div>
</div>
{/strip}
