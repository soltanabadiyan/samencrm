{*<!-- TelegramBot – main page -->*}
{strip}
<div class="main-container clearfix">
<div class="content-area full-width tg-page" id="tgIndex">
	<div class="clearfix tg-page-head">
		<h3 class="pull-left"><i class="fa fa-telegram"></i> {vtranslate('TelegramBot', 'TelegramBot')}</h3>
		<div class="pull-right tg-head-stats">
			<span class="label label-success">{vtranslate('LBL_LINKED_CONTACTS', 'TelegramBot')}: {$LINKED_COUNT}</span>&nbsp;
			<span class="label label-info">{vtranslate('LBL_STATUS_queued', 'TelegramBot')}: {$QUEUE.queued|default:0}</span>&nbsp;
			<span class="label label-default">{vtranslate('LBL_STATUS_sent', 'TelegramBot')}: {$QUEUE.sent|default:0}</span>&nbsp;
			<span class="label label-danger">{vtranslate('LBL_STATUS_failed', 'TelegramBot')}: {$QUEUE.failed|default:0}</span>&nbsp;
			<button type="button" class="btn btn-default btn-sm tg-run-worker" title="{$WORKER_RESULT|escape:'html'}"><i class="fa fa-refresh"></i> {vtranslate('LBL_RUN_WORKER', 'TelegramBot')}</button>
		</div>
	</div>
	<p class="text-muted small">{vtranslate('LBL_WORKER_LAST', 'TelegramBot')}: {$WORKER_LAST|default:'—'}</p>
	{if !$BOTS}
		<div class="alert alert-warning">{vtranslate('LBL_NO_BOT', 'TelegramBot')} {if $IS_ADMIN}<a href="index.php?module=TelegramBot&view=Settings">{vtranslate('LBL_SETTINGS', 'TelegramBot')}</a>{/if}</div>
	{/if}

	<ul class="nav nav-tabs tg-tabs">
		<li class="{if $TAB eq 'compose'}active{/if}"><a href="#tg-compose" data-toggle="tab">{vtranslate('LBL_BULK_SEND', 'TelegramBot')}</a></li>
		<li class="{if $TAB eq 'campaigns'}active{/if}"><a href="#tg-campaigns" data-toggle="tab">{vtranslate('LBL_CAMPAIGNS', 'TelegramBot')}</a></li>
		<li class="{if $TAB eq 'messages'}active{/if}"><a href="#tg-messages" data-toggle="tab">{vtranslate('LBL_MESSAGES', 'TelegramBot')}</a></li>
		<li class="{if $TAB eq 'templates'}active{/if}"><a href="#tg-templates" data-toggle="tab">{vtranslate('LBL_TEMPLATES', 'TelegramBot')}</a></li>
	</ul>
	<div class="tab-content tg-tab-content">
		{* ---------------------------------------------------------- bulk compose *}
		<div class="tab-pane {if $TAB eq 'compose'}active{/if}" id="tg-compose">
			<form class="tg-send-form tg-bulk-form" enctype="multipart/form-data" onsubmit="return false;">
				<input type="hidden" name="module" value="TelegramBot" />
				<input type="hidden" name="action" value="Ajax" />
				<input type="hidden" name="mode" value="send" />
				<div class="row">
					<div class="col-md-5">
						<div class="panel panel-default"><div class="panel-heading"><strong>{vtranslate('LBL_RECIPIENTS', 'TelegramBot')}</strong></div><div class="panel-body">
							<div class="form-group">
								<label class="control-label">{vtranslate('LBL_TARGET_TYPE', 'TelegramBot')}</label>
								<select name="target_type" class="inputElement tg-target-type">
									<option value="tag">{vtranslate('LBL_TARGET_tag', 'TelegramBot')}</option>
									<option value="group">{vtranslate('LBL_TARGET_group', 'TelegramBot')}</option>
									<option value="filter">{vtranslate('LBL_TARGET_filter', 'TelegramBot')}</option>
									<option value="picklist">{vtranslate('LBL_TARGET_picklist', 'TelegramBot')}</option>
									{if $MISSING_ITEMS}<option value="missing">{vtranslate('LBL_TARGET_missing', 'TelegramBot')}</option>{/if}
									<option value="linked">{vtranslate('LBL_TARGET_linked', 'TelegramBot')}</option>
								</select>
							</div>
							<div class="form-group tg-target tg-target-tag">
								<select name="tags[]" multiple class="select2 inputElement" style="width:100%" data-placeholder="{vtranslate('LBL_TARGET_tag', 'TelegramBot')}">
									{foreach item=T from=$TAGS}<option value="{$T.id}">{$T.tag|escape:'html'} ({$T.n})</option>{/foreach}
								</select>
							</div>
							<div class="form-group tg-target tg-target-group hide">
								<select name="groups[]" multiple class="select2 inputElement" style="width:100%">
									{foreach item=G from=$GROUPS}<option value="{$G.id}">{$G.name|escape:'html'} ({$G.n})</option>{/foreach}
								</select>
							</div>
							<div class="form-group tg-target tg-target-filter hide">
								<select name="filters[]" multiple class="select2 inputElement" style="width:100%">
									{foreach item=F from=$FILTERS}<option value="{$F.id}">{$F.name|escape:'html'}</option>{/foreach}
								</select>
							</div>
							<div class="form-group tg-target tg-target-picklist hide">
								<select name="pl_field" class="inputElement tg-pl-field" style="margin-bottom:6px">
									<option value="">{vtranslate('LBL_CHOOSE_FIELD', 'TelegramBot')}</option>
									{foreach key=FN item=FL from=$PICKLISTS}<option value="{$FN}" {if $FN eq 'cf_ss_level'}selected{/if}>{$FL|escape:'html'}</option>{/foreach}
								</select>
								<select name="pl_values[]" multiple class="select2 inputElement tg-pl-values" style="width:100%"></select>
							</div>
							<div class="form-group tg-target tg-target-missing hide">
								<select name="missing[]" multiple class="select2 inputElement" style="width:100%">
									{foreach item=MI from=$MISSING_ITEMS}<option value="{$MI.value|escape:'html'}">{$MI.value|escape:'html'} ({$MI.count})</option>{/foreach}
								</select>
								<p class="help-block small">{vtranslate('LBL_MISSING_HELP', 'TelegramBot')}</p>
							</div>
							<div class="tg-target tg-target-linked hide"><p class="help-block">{vtranslate('LBL_LINKED_HELP', 'TelegramBot')}</p></div>
							<button type="button" class="btn btn-default btn-sm tg-count-btn"><i class="fa fa-users"></i> {vtranslate('LBL_COUNT', 'TelegramBot')}</button>
							<div class="tg-count-box" style="margin-top:8px"></div>
							<p class="help-block small">{vtranslate('LBL_LIST_HINT', 'TelegramBot')}</p>
						</div></div>
					</div>
					<div class="col-md-7">
						<div class="panel panel-default"><div class="panel-heading"><strong>{vtranslate('LBL_MESSAGE', 'TelegramBot')}</strong></div><div class="panel-body">
							<div class="form-group">
								<label class="control-label">{vtranslate('LBL_CAMPAIGN_TITLE', 'TelegramBot')}</label>
								<input type="text" name="title" class="form-control" />
							</div>
							{include file="Compose.tpl"|vtemplate_path:'TelegramBot' COMPACT=false}
							<div class="text-right">
								<button class="btn btn-success tg-send-btn" type="button" {if !$BOTS}disabled{/if}><i class="fa fa-paper-plane"></i> <strong>{vtranslate('LBL_SEND', 'TelegramBot')}</strong></button>
							</div>
						</div></div>
					</div>
				</div>
			</form>
		</div>

		{* ---------------------------------------------------------- campaigns *}
		<div class="tab-pane {if $TAB eq 'campaigns'}active{/if}" id="tg-campaigns">
			<div class="table-responsive">
			<table class="table table-bordered table-condensed listview-table tg-table">
				<thead><tr>
					<th>#</th><th>{vtranslate('LBL_CAMPAIGN_TITLE', 'TelegramBot')}</th><th>{vtranslate('LBL_RECIPIENTS', 'TelegramBot')}</th>
					<th>{vtranslate('LBL_WHEN', 'TelegramBot')}</th><th>{vtranslate('LBL_STATE', 'TelegramBot')}</th>
					<th>{vtranslate('LBL_STATUS_sent', 'TelegramBot')}</th><th>{vtranslate('LBL_STATUS_queued', 'TelegramBot')}</th>
					<th>{vtranslate('LBL_STATUS_failed', 'TelegramBot')}</th><th>{vtranslate('LBL_NOT_REACHABLE', 'TelegramBot')}</th><th></th>
				</tr></thead>
				<tbody>
				{foreach item=C from=$CAMPAIGNS}
					<tr>
						<td>{$C.id}</td>
						<td>{if $C.kind eq 'birthday'}🎂 {/if}{$C.title|escape:'html'}{if $C.msg_type neq 'text'} <span class="label label-default">{vtranslate('LBL_TYPE_'|cat:$C.msg_type, 'TelegramBot')}</span>{/if}
							<div class="text-muted small tg-ellipsis" title="{$C.body|escape:'html'}">{$C.body|truncate:90|escape:'html'}</div></td>
						<td class="small">{$C.target_label|escape:'html'}</td>
						<td>{$C.when}</td>
						<td><span class="label tg-cst-{$C.status}">{vtranslate('LBL_CSTATUS_'|cat:$C.status, 'TelegramBot')}</span>{if $C.error}<div class="text-danger small">{$C.error|escape:'html'}</div>{/if}</td>
						<td>{$C.stats.sent|default:0}</td>
						<td>{($C.stats.queued|default:0)+($C.stats.sending|default:0)}</td>
						<td>{if $C.stats.failed|default:0}<a href="index.php?module=TelegramBot&view=Index&tab=messages&status=failed">{$C.stats.failed}</a>{else}0{/if}</td>
						<td>{$C.skipped}</td>
						<td>{if $C.status eq 'scheduled' || $C.status eq 'queued'}<button type="button" class="btn btn-default btn-xs tg-cancel-campaign" data-id="{$C.id}"><i class="fa fa-ban"></i> {vtranslate('LBL_CANCEL_CAMPAIGN', 'TelegramBot')}</button>{/if}</td>
					</tr>
				{foreachelse}
					<tr><td colspan="10" class="text-muted text-center">{vtranslate('LBL_NO_CAMPAIGNS', 'TelegramBot')}</td></tr>
				{/foreach}
				</tbody>
			</table>
			</div>
		</div>

		{* ---------------------------------------------------------- message log *}
		<div class="tab-pane {if $TAB eq 'messages'}active{/if}" id="tg-messages">
			<form class="form-inline tg-msg-filter" method="get" action="index.php" style="margin-bottom:10px">
				<input type="hidden" name="module" value="TelegramBot" /><input type="hidden" name="view" value="Index" /><input type="hidden" name="tab" value="messages" />
				<select name="direction" class="form-control input-sm">
					<option value="">{vtranslate('LBL_ALL_DIRECTIONS', 'TelegramBot')}</option>
					<option value="in" {if $F_DIRECTION eq 'in'}selected{/if}>{vtranslate('LBL_DIR_in', 'TelegramBot')}</option>
					<option value="out" {if $F_DIRECTION eq 'out'}selected{/if}>{vtranslate('LBL_DIR_out', 'TelegramBot')}</option>
				</select>&nbsp;
				<select name="status" class="form-control input-sm">
					<option value="">{vtranslate('LBL_ALL_STATUSES', 'TelegramBot')}</option>
					{foreach item=ST from=['queued','sent','failed','skipped','cancelled','received']}<option value="{$ST}" {if $F_STATUS eq $ST}selected{/if}>{vtranslate('LBL_STATUS_'|cat:$ST, 'TelegramBot')}</option>{/foreach}
				</select>&nbsp;
				<label class="checkbox-inline"><input type="checkbox" name="unlinked" value="1" {if $F_UNLINKED}checked{/if} /> {vtranslate('LBL_UNLINKED_ONLY', 'TelegramBot')}</label>&nbsp;
				<button class="btn btn-default btn-sm" type="submit"><i class="fa fa-filter"></i></button>
			</form>
			<div class="table-responsive">
			<table class="table table-bordered table-condensed listview-table tg-table">
				<thead><tr><th>{vtranslate('LBL_WHEN', 'TelegramBot')}</th><th></th><th>{vtranslate('SINGLE_Contacts', 'Contacts')}</th><th>{vtranslate('LBL_MESSAGE', 'TelegramBot')}</th><th>{vtranslate('LBL_STATE', 'TelegramBot')}</th><th></th></tr></thead>
				<tbody>
				{foreach item=M from=$MESSAGES}
					<tr class="tg-row-{$M.status}">
						<td class="nowrap">{$M.when}</td>
						<td>{if $M.direction eq 'in'}<i class="fa fa-arrow-down text-success" title="{vtranslate('LBL_DIR_in', 'TelegramBot')}"></i>{else}<i class="fa fa-arrow-up text-info" title="{vtranslate('LBL_DIR_out', 'TelegramBot')}"></i>{/if}</td>
						<td>{if $M.contactid}<a href="index.php?module=Contacts&view=Detail&record={$M.contactid}&tgtab=1">{$M.firstname|escape:'html'} {$M.lastname|escape:'html'}</a>{else}<span class="text-muted">{vtranslate('LBL_UNKNOWN_CHAT', 'TelegramBot')} <span dir="ltr">{$M.chat_id}</span></span>{/if}</td>
						<td>{if $M.msg_type neq 'text'}<span class="label label-default">{vtranslate('LBL_TYPE_'|cat:$M.msg_type, 'TelegramBot')}</span> {if $M.media_path}<a href="index.php?module=TelegramBot&action=Download&id={$M.id}">{$M.media_name|escape:'html'}</a>{/if} {/if}<span dir="auto">{$M.body|truncate:160|escape:'html'}</span></td>
						<td><span class="tg-status" title="{$M.error|escape:'html'}">{vtranslate('LBL_STATUS_'|cat:$M.status, 'TelegramBot')}</span>{if $M.error && $M.status eq 'failed'}<div class="text-danger small">{$M.error|truncate:120|escape:'html'}</div>{/if}</td>
						<td>{if $M.direction eq 'out' && $M.status eq 'failed'}<button type="button" class="btn btn-default btn-xs tg-retry" data-id="{$M.id}">{vtranslate('LBL_RETRY', 'TelegramBot')}</button>{/if}</td>
					</tr>
				{foreachelse}
					<tr><td colspan="6" class="text-muted text-center">{vtranslate('LBL_NO_MESSAGES', 'TelegramBot')}</td></tr>
				{/foreach}
				</tbody>
			</table>
			</div>
		</div>

		{* ---------------------------------------------------------- templates *}
		<div class="tab-pane {if $TAB eq 'templates'}active{/if}" id="tg-templates">
			<div class="row">
				<div class="col-md-5">
					<table class="table table-bordered table-condensed tg-table">
						<tbody>
						{foreach item=T from=$TEMPLATES}
							<tr><td><a href="#" class="tg-edit-template" data-id="{$T.id}">{$T.title|escape:'html'}</a><div class="text-muted small">{$T.body|truncate:80|escape:'html'}</div></td>
								<td style="width:40px"><button type="button" class="btn btn-default btn-xs tg-delete-template" data-id="{$T.id}"><i class="fa fa-trash"></i></button></td></tr>
						{foreachelse}
							<tr><td class="text-muted">{vtranslate('LBL_NO_TEMPLATES', 'TelegramBot')}</td></tr>
						{/foreach}
						</tbody>
					</table>
				</div>
				<div class="col-md-7">
					<form class="tg-template-form" onsubmit="return false;">
						<input type="hidden" name="id" value="" />
						<div class="form-group"><label class="control-label">{vtranslate('LBL_TEMPLATE_TITLE', 'TelegramBot')}</label><input type="text" name="title" class="form-control" /></div>
						<div class="form-group">
							<label class="control-label">{vtranslate('LBL_MERGE_FIELD', 'TelegramBot')}</label>
							<select class="inputElement tg-merge-pick">
								<option value="">{vtranslate('LBL_INSERT_FIELD', 'TelegramBot')}</option>
								{foreach key=TOKEN item=LABEL from=$MERGE_FIELDS}<option value="{$TOKEN|escape:'html'}">{$LABEL|escape:'html'} – {$TOKEN|escape:'html'}</option>{/foreach}
							</select>
						</div>
						<div class="form-group"><label class="control-label">{vtranslate('LBL_MESSAGE_TEXT', 'TelegramBot')}</label><textarea name="body" rows="8" class="form-control tg-body" dir="auto"></textarea></div>
						<button type="button" class="btn btn-success tg-save-template"><i class="fa fa-save"></i> {vtranslate('LBL_SAVE', 'Vtiger')}</button>
						<button type="button" class="btn btn-default tg-new-template">{vtranslate('LBL_NEW_TEMPLATE', 'TelegramBot')}</button>
					</form>
				</div>
			</div>
		</div>
	</div>
</div>
</div>
{/strip}
