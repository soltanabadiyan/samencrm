{*<!-- TelegramBot – settings page (admins) -->*}
{strip}
<div class="main-container clearfix">
<div class="content-area full-width tg-page" id="tgSettings">
	<div class="clearfix tg-page-head">
		<h3 class="pull-left"><i class="fa fa-cog"></i> {vtranslate('LBL_SETTINGS_TITLE', 'TelegramBot')}</h3>
		<div class="pull-right"><a class="btn btn-default btn-sm" href="index.php?module=TelegramBot&view=Index"><i class="fa fa-telegram"></i> {vtranslate('TelegramBot', 'TelegramBot')}</a></div>
	</div>

	{* ---------------------------------------------------------- bots *}
	<div class="panel panel-default">
		<div class="panel-heading"><strong>{vtranslate('LBL_BOTS', 'TelegramBot')}</strong></div>
		<div class="panel-body">
			<p class="help-block">{vtranslate('LBL_BOTS_HELP', 'TelegramBot')}</p>
			<div class="table-responsive">
			<table class="table table-bordered table-condensed tg-table">
				<thead><tr><th>{vtranslate('LBL_BOT_TITLE', 'TelegramBot')}</th><th>{vtranslate('LBL_USERNAME', 'TelegramBot')}</th><th>{vtranslate('LBL_TOKEN', 'TelegramBot')}</th>
					<th>{vtranslate('LBL_LAST_POLL', 'TelegramBot')}</th><th>{vtranslate('LBL_STATE', 'TelegramBot')}</th><th></th></tr></thead>
				<tbody>
				{foreach item=B from=$BOTS}
					<tr data-id="{$B.id}">
						<td>{$B.title|escape:'html'} {if $B.is_default}<span class="label label-primary">{vtranslate('LBL_DEFAULT', 'TelegramBot')}</span>{/if}</td>
						<td dir="ltr">{if $B.username}<a href="https://t.me/{$B.username|escape:'url'}" target="_blank" rel="noopener">@{$B.username|escape:'html'}</a>{else}—{/if}</td>
						<td dir="ltr"><code>{$B.token_masked|escape:'html'}</code></td>
						<td>{$B.last_poll_fmt|default:'—'}{if $B.last_error}<div class="text-danger small">{$B.last_error|escape:'html'}</div>{/if}</td>
						<td><label class="checkbox-inline"><input type="checkbox" class="tg-bot-active" {if $B.active}checked{/if} /> {vtranslate('LBL_ACTIVE', 'TelegramBot')}</label></td>
						<td class="nowrap">
							<button type="button" class="btn btn-default btn-xs tg-bot-test"><i class="fa fa-plug"></i> {vtranslate('LBL_TEST', 'TelegramBot')}</button>&nbsp;
							{if !$B.is_default}<button type="button" class="btn btn-default btn-xs tg-bot-default">{vtranslate('LBL_MAKE_DEFAULT', 'TelegramBot')}</button>&nbsp;{/if}
							<button type="button" class="btn btn-default btn-xs tg-bot-edit" data-title="{$B.title|escape:'html'}"><i class="fa fa-pencil"></i></button>&nbsp;
							<button type="button" class="btn btn-danger btn-xs tg-bot-delete"><i class="fa fa-trash"></i></button>
						</td>
					</tr>
				{foreachelse}
					<tr><td colspan="6" class="text-muted text-center">{vtranslate('LBL_NO_BOT', 'TelegramBot')}</td></tr>
				{/foreach}
				</tbody>
			</table>
			</div>
			<form class="form-inline tg-bot-form" onsubmit="return false;" autocomplete="off">
				<input type="hidden" name="id" value="" />
				<input type="text" name="title" class="form-control" placeholder="{vtranslate('LBL_BOT_TITLE', 'TelegramBot')}" />&nbsp;
				<input type="password" name="token" class="form-control" style="min-width:340px" dir="ltr" autocomplete="new-password" placeholder="123456789:AA… ({vtranslate('LBL_TOKEN', 'TelegramBot')})" />&nbsp;
				<button type="button" class="btn btn-success tg-bot-save"><i class="fa fa-plus"></i> <span>{vtranslate('LBL_ADD_BOT', 'TelegramBot')}</span></button>
			</form>
		</div>
	</div>

	<form class="tg-settings-form" onsubmit="return false;">
	{* ---------------------------------------------------------- connection *}
	<div class="panel panel-default">
		<div class="panel-heading"><strong>{vtranslate('LBL_CONNECTION', 'TelegramBot')}</strong></div>
		<div class="panel-body">
			<p class="help-block">{vtranslate('LBL_CONNECTION_HELP', 'TelegramBot')}</p>
			<div class="row">
				<div class="col-md-6 form-group"><label class="control-label">{vtranslate('LBL_API_BASE', 'TelegramBot')}</label><input type="text" name="api_base" class="form-control" dir="ltr" value="{$S.api_base|escape:'html'}" /></div>
				<div class="col-md-6 form-group"><label class="control-label">{vtranslate('LBL_PROXY', 'TelegramBot')}</label><input type="text" name="proxy" class="form-control" dir="ltr" value="{$S.proxy|escape:'html'}" placeholder="socks5h://127.0.0.1:1080" /></div>
			</div>
			<button type="button" class="btn btn-default btn-sm tg-test-proxy"><i class="fa fa-plug"></i> {vtranslate('LBL_TEST_CONNECTION', 'TelegramBot')}</button>
			<span class="tg-proxy-result" style="margin:0 10px"></span>
			<div class="row" style="margin-top:12px">
				<div class="col-md-3 form-group"><label class="control-label">{vtranslate('LBL_RATE', 'TelegramBot')}</label><input type="number" min="1" max="30" name="rate_per_sec" class="form-control" value="{$S.rate_per_sec|escape:'html'}" /></div>
				<div class="col-md-3 form-group"><label class="control-label">{vtranslate('LBL_MAX_ATTEMPTS', 'TelegramBot')}</label><input type="number" min="1" max="20" name="max_attempts" class="form-control" value="{$S.max_attempts|escape:'html'}" /></div>
				<div class="col-md-3 form-group"><label class="control-label">{vtranslate('LBL_WORKER_SECONDS', 'TelegramBot')}</label><input type="number" min="10" max="55" name="worker_seconds" class="form-control" value="{$S.worker_seconds|escape:'html'}" /></div>
				<div class="col-md-3 form-group"><label class="control-label">{vtranslate('LBL_TIMEZONE', 'TelegramBot')}</label><input type="text" name="timezone" class="form-control" dir="ltr" value="{$S.timezone|escape:'html'}" /></div>
			</div>
			<p class="small text-muted">{vtranslate('LBL_WORKER_LAST', 'TelegramBot')}: {$WORKER_LAST|default:'—'} <code dir="ltr">{$WORKER_RESULT|escape:'html'}</code></p>
			<p class="small text-muted">{foreach item=C from=$CRONS}{$C.name}: {if $C.status eq 1}✔{else}✖{/if} ({$C.frequency}s, {$C.laststart_fmt|default:'—'})&nbsp;&nbsp;{/foreach}</p>
		</div>
	</div>

	{* ---------------------------------------------------------- bot texts *}
	<div class="panel panel-default">
		<div class="panel-heading"><strong>{vtranslate('LBL_BOT_TEXTS', 'TelegramBot')}</strong></div>
		<div class="panel-body">
			<p class="help-block">{vtranslate('LBL_BOT_TEXTS_HELP', 'TelegramBot')}</p>
			<div class="row">
				{foreach item=K from=['welcome_text','ask_phone_text','not_found_text','ambiguous_text','stop_text']}
					<div class="col-md-6 form-group"><label class="control-label">{vtranslate('LBL_S_'|cat:$K, 'TelegramBot')}</label><textarea name="{$K}" rows="3" class="form-control" dir="auto">{$S[$K]|escape:'html'}</textarea></div>
				{/foreach}
				<div class="col-md-6 form-group"><label class="control-label">{vtranslate('LBL_S_phone_button_text', 'TelegramBot')}</label><input type="text" name="phone_button_text" class="form-control" value="{$S.phone_button_text|escape:'html'}" /></div>
			</div>
		</div>
	</div>

	{* ---------------------------------------------------------- birthdays *}
	<div class="panel panel-default">
		<div class="panel-heading"><strong>🎂 {vtranslate('LBL_BIRTHDAYS', 'TelegramBot')}</strong></div>
		<div class="panel-body">
			<div class="checkbox"><label><input type="checkbox" name="birthday_enabled" value="1" {if $S.birthday_enabled}checked{/if} /> {vtranslate('LBL_BIRTHDAY_ENABLED', 'TelegramBot')}</label></div>
			<div class="row">
				<div class="col-md-3 form-group"><label class="control-label">{vtranslate('LBL_BIRTHDAY_HOUR', 'TelegramBot')}</label><input type="number" min="0" max="23" name="birthday_hour" class="form-control" value="{$S.birthday_hour|escape:'html'}" /></div>
				<div class="col-md-3 form-group"><label class="control-label">{vtranslate('LBL_BOT', 'TelegramBot')}</label>
					<select name="birthday_botid" class="form-control"><option value="0">{vtranslate('LBL_DEFAULT', 'TelegramBot')}</option>
						{foreach item=B from=$BOTS}<option value="{$B.id}" {if $S.birthday_botid eq $B.id}selected{/if}>{$B.title|escape:'html'}</option>{/foreach}</select></div>
				<div class="col-md-6 form-group"><label class="control-label">{vtranslate('LBL_LAST_BIRTHDAY_RUN', 'TelegramBot')}</label><p class="form-control-static">{$S.birthday_last|default:'—'}</p></div>
			</div>
			<div class="form-group"><label class="control-label">{vtranslate('LBL_MESSAGE_TEXT', 'TelegramBot')}</label>
				<textarea name="birthday_text" rows="3" class="form-control" dir="auto">{$S.birthday_text|escape:'html'}</textarea>
				<p class="help-block small">{vtranslate('LBL_MERGE_HELP', 'TelegramBot')} {foreach key=TOKEN item=LABEL from=$MERGE_FIELDS name=mf}{if $smarty.foreach.mf.index lt 8}<code>{$TOKEN|escape:'html'}</code> {/if}{/foreach}</p>
			</div>
			<button type="button" class="btn btn-default btn-sm tg-run-birthdays">{vtranslate('LBL_RUN_BIRTHDAYS', 'TelegramBot')}</button>
		</div>
	</div>
	<div class="text-center" style="margin-bottom:30px"><button type="button" class="btn btn-success tg-save-settings"><i class="fa fa-save"></i> <strong>{vtranslate('LBL_SAVE', 'Vtiger')}</strong></button></div>
	</form>
</div>
</div>
{/strip}
