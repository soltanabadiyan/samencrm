{*<!-- SamenTheme – Settings > Extensions > SamenCRM theme (admins) -->*}
{strip}
<div class="main-container clearfix">
<div class="content-area full-width samen-ext" id="samenThemeSettings" data-module="SamenTheme">
	<div class="samen-ext-head">
		<img class="samen-ext-logo" src="layouts/v7/modules/SamenTheme/resources/img/emblem.png" alt="" width="48" height="48" />
		<div>
			<h3>{vtranslate('LBL_ST_TITLE', 'SamenTheme')}</h3>
			<p class="samen-ext-sub">{vtranslate('LBL_ST_SUBTITLE', 'SamenTheme')} <span class="samen-ext-ver" dir="ltr">v{$ST.version}</span></p>
		</div>
		<div class="samen-ext-actions">
			<button type="button" class="btn btn-primary" data-samen-action="apply" data-done="{vtranslate('LBL_ST_DONE', 'SamenTheme')}"><i class="fa fa-refresh"></i>&nbsp;{vtranslate('LBL_ST_APPLY', 'SamenTheme')}</button>
			<button type="button" class="btn btn-default" data-samen-action="logo" data-done="{vtranslate('LBL_ST_DONE', 'SamenTheme')}"><i class="fa fa-picture-o"></i>&nbsp;{vtranslate('LBL_ST_LOGO', 'SamenTheme')}</button>
		</div>
	</div>

	<div class="samen-ext-grid">
		<section class="samen-ext-card">
			<h4>{vtranslate('LBL_ST_STATUS', 'SamenTheme')}</h4>
			<ul class="samen-ext-checks">
				<li class="{if $ST.skin_ok}ok{else}bad{/if}"><span>{vtranslate('LBL_ST_SKIN', 'SamenTheme')}</span><b>{if $ST.skin_ok}{count($ST.apps)} {vtranslate('LBL_ST_APPS', 'SamenTheme')}{else}{vtranslate('LBL_ST_MISSING', 'SamenTheme')}{/if}</b></li>
				<li class="{if $ST.links}ok{else}bad{/if}"><span>{vtranslate('LBL_ST_SCRIPT', 'SamenTheme')}</span><b>{if $ST.links}{vtranslate('LBL_ST_ACTIVE', 'SamenTheme')}{else}{vtranslate('LBL_ST_MISSING', 'SamenTheme')}{/if}</b></li>
				<li class="{if $ST.login_ok}ok{else}bad{/if}"><span>{vtranslate('LBL_ST_LOGIN', 'SamenTheme')}</span><b>{if $ST.login_ok}{vtranslate('LBL_ST_ACTIVE', 'SamenTheme')}{elseif !$ST.login_writable}{vtranslate('LBL_ST_NOT_WRITABLE', 'SamenTheme')}{else}{vtranslate('LBL_ST_MISSING', 'SamenTheme')}{/if}</b></li>
				<li class="{if $ST.logo_ok}ok{else}warn{/if}"><span>{vtranslate('LBL_ST_COMPANY_LOGO', 'SamenTheme')}</span><b>{if $ST.logo_ok}{vtranslate('LBL_ST_ACTIVE', 'SamenTheme')}{else}{vtranslate('LBL_ST_CUSTOM', 'SamenTheme')}{/if}</b></li>
				<li class="{if $ST.session_skin eq 'samen'}ok{else}warn{/if}"><span>{vtranslate('LBL_ST_SESSION_SKIN', 'SamenTheme')}</span><b dir="ltr">{$ST.session_skin|default:'—'|escape:'html'}</b></li>
			</ul>
			{if $ST.session_skin neq 'samen'}<p class="samen-ext-note">{vtranslate('LBL_ST_SESSION_HELP', 'SamenTheme')}</p>{/if}
		</section>

		<section class="samen-ext-card">
			<h4>{vtranslate('LBL_ST_BRAND', 'SamenTheme')}</h4>
			<dl class="samen-ext-dl">
				<dt>{vtranslate('LBL_ST_ORG', 'SamenTheme')}</dt><dd>{$ST.org_name|escape:'html'}</dd>
				<dt>{vtranslate('LBL_ST_WEBSITE', 'SamenTheme')}</dt><dd dir="ltr">{$ST.website|escape:'html'|default:'—'}</dd>
			</dl>
			<div class="samen-ext-palette">
				{foreach item=C from=$PALETTE}
					<div class="samen-ext-swatch"><i style="background:{$C[0]}"></i><span>{$C[1]}</span><code dir="ltr">{$C[0]}</code></div>
				{/foreach}
			</div>
			<p class="samen-ext-links">
				<a href="index.php?parent=Settings&module=Vtiger&view=CompanyDetails"><i class="fa fa-building-o"></i>&nbsp;{vtranslate('LBL_ST_COMPANY_PAGE', 'SamenTheme')}</a>
				<a href="index.php?module=PersianCalendar&view=Settings&app=SETTINGS"><i class="fa fa-calendar"></i>&nbsp;{vtranslate('LBL_ST_CALENDAR_PAGE', 'SamenTheme')}</a>
			</p>
		</section>
	</div>

	<section class="samen-ext-card">
		<h4>{vtranslate('LBL_ST_FILES', 'SamenTheme')}</h4>
		<div class="table-responsive">
		<table class="table samen-ext-table">
			<thead><tr><th>{vtranslate('LBL_ST_APP', 'SamenTheme')}</th><th>{vtranslate('LBL_ST_SIZE', 'SamenTheme')}</th><th>{vtranslate('LBL_ST_BUILT', 'SamenTheme')}</th></tr></thead>
			<tbody>
			{foreach item=A from=$ST.apps}
				<tr><td>{vtranslate('APP_'|cat:$A.app, 'SamenTheme')}</td><td dir="ltr">{$A.size} KB</td><td dir="ltr">{$A.time}</td></tr>
			{/foreach}
			</tbody>
		</table>
		</div>
		<p class="samen-ext-note">{vtranslate('LBL_ST_FILES_HELP', 'SamenTheme')}</p>
	</section>
</div>
</div>
{/strip}
