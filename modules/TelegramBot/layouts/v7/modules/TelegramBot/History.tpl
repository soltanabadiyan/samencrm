{*<!-- TelegramBot – "Telegram" tab of the Contact detail view -->*}
{strip}
<div class="tg-history" data-record="{$RECORD}">
	<div class="row">
		<div class="col-md-4 tg-side">
			<h4><i class="fa fa-telegram"></i> {vtranslate('LBL_TELEGRAM_STATUS', 'TelegramBot')}</h4>
			{if !$BOTS}<div class="alert alert-warning">{vtranslate('LBL_NO_BOT', 'TelegramBot')}</div>{/if}
			{foreach item=B from=$BOTS}
				{assign var=L value=$LINKS[$B.id]|default:null}
				<div class="panel panel-default tg-link-panel" data-botid="{$B.id}">
					<div class="panel-heading"><strong>{$B.title|escape:'html'}</strong> <span class="text-muted">@{$B.username|escape:'html'}</span></div>
					<div class="panel-body">
						{if $L}
							<p>
								<span class="label {if $L.blocked}label-danger{elseif $L.optout}label-warning{else}label-success{/if}">
									{if $L.blocked}{vtranslate('LBL_BLOCKED', 'TelegramBot')}{elseif $L.optout}{vtranslate('LBL_OPTED_OUT', 'TelegramBot')}{else}{vtranslate('LBL_LINKED', 'TelegramBot')}{/if}
								</span>
								&nbsp;{$L.tg_name|escape:'html'} {if $L.tg_username}<a href="https://t.me/{$L.tg_username|escape:'url'}" target="_blank" rel="noopener">@{$L.tg_username|escape:'html'}</a>{/if}
							</p>
							<p class="text-muted small">{vtranslate('LBL_LINKED_VIA', 'TelegramBot')}: {vtranslate('LBL_VIA_'|cat:$L.linked_via, 'TelegramBot')} – {$L.linked_fmt}{if $L.phone} – <span dir="ltr">{$L.phone|escape:'html'}</span>{/if}</p>
							<div class="checkbox"><label><input type="checkbox" class="tg-optout" {if $L.optout}checked{/if} /> {vtranslate('LBL_OPTOUT_FLAG', 'TelegramBot')}</label></div>
							<button type="button" class="btn btn-default btn-xs tg-unlink"><i class="fa fa-chain-broken"></i> {vtranslate('LBL_UNLINK', 'TelegramBot')}</button>
						{else}
							<p><span class="label label-default">{vtranslate('LBL_NOT_LINKED_SHORT', 'TelegramBot')}</span></p>
						{/if}
						{if $DEEPLINKS[$B.id]}
							<label class="control-label small" style="margin-top:8px">{vtranslate('LBL_DEEPLINK', 'TelegramBot')}</label>
							<div class="input-group">
								<input type="text" class="form-control input-sm tg-deeplink" readonly dir="ltr" value="{$DEEPLINKS[$B.id]|escape:'html'}" />
								<span class="input-group-btn"><button class="btn btn-default btn-sm tg-copy" type="button" title="{vtranslate('LBL_COPY', 'TelegramBot')}"><i class="fa fa-copy"></i></button></span>
							</div>
							<p class="help-block small">{vtranslate('LBL_DEEPLINK_HELP', 'TelegramBot')}</p>
						{/if}
					</div>
				</div>
			{/foreach}
		</div>
		<div class="col-md-8">
			<div class="clearfix" style="margin-bottom:8px">
				<h4 class="pull-left">{vtranslate('LBL_CONVERSATION', 'TelegramBot')} ({$MESSAGES|@count})</h4>
				<button type="button" class="btn btn-default btn-sm pull-right tg-refresh"><i class="fa fa-refresh"></i> {vtranslate('LBL_REFRESH', 'TelegramBot')}</button>
			</div>
			<div class="tg-thread">
				{foreach item=M from=$MESSAGES}
					<div class="tg-msg tg-{$M.direction} tg-st-{$M.status}">
						<div class="tg-bubble">
							{if $M.media_path neq '' || $M.media_name neq ''}
								<div class="tg-media-item">
									{if $M.is_image}
										<a href="index.php?module=TelegramBot&action=Download&id={$M.id}&inline=1" target="_blank"><img src="index.php?module=TelegramBot&action=Download&id={$M.id}&inline=1" alt="" /></a>
									{elseif $M.media_path neq ''}
										<a href="index.php?module=TelegramBot&action=Download&id={$M.id}"><i class="fa fa-paperclip"></i> {vtranslate('LBL_TYPE_'|cat:$M.msg_type, 'TelegramBot')}: {$M.media_name|escape:'html'}</a>
									{else}
										<i class="fa fa-paperclip"></i> {$M.media_name|escape:'html'}
									{/if}
								</div>
							{elseif $M.msg_type neq 'text'}
								<div class="text-muted small">{vtranslate('LBL_TYPE_'|cat:$M.msg_type, 'TelegramBot')}</div>
							{/if}
							{if $M.body neq ''}<div class="tg-text" dir="auto">{$M.body|escape:'html'}</div>{/if}
							<div class="tg-meta">
								{$M.when}
								{if $M.direction eq 'out'}
									&nbsp;·&nbsp;<span class="tg-status" title="{$M.error|escape:'html'}">{vtranslate('LBL_STATUS_'|cat:$M.status, 'TelegramBot')}</span>
									{if $M.ufirst || $M.ulast}&nbsp;·&nbsp;{$M.ufirst|escape:'html'} {$M.ulast|escape:'html'}{/if}
									{if $M.status eq 'failed'}&nbsp;<a href="#" class="tg-retry" data-id="{$M.id}">{vtranslate('LBL_RETRY', 'TelegramBot')}</a>{/if}
								{/if}
							</div>
							{if $M.error neq '' && $M.status eq 'failed'}<div class="tg-error small">{$M.error|escape:'html'}</div>{/if}
						</div>
					</div>
				{foreachelse}
					<div class="text-muted tg-empty">{vtranslate('LBL_NO_MESSAGES', 'TelegramBot')}</div>
				{/foreach}
			</div>
			{if $BOTS}
				<form class="tg-send-form tg-reply-form" enctype="multipart/form-data" onsubmit="return false;">
					<input type="hidden" name="module" value="TelegramBot" />
					<input type="hidden" name="action" value="Ajax" />
					<input type="hidden" name="mode" value="send" />
					<input type="hidden" name="target_type" value="ids" />
					<input type="hidden" name="record" value="{$RECORD}" />
					{include file="Compose.tpl"|vtemplate_path:'TelegramBot' COMPACT=true}
					<div class="text-right">
						<button class="btn btn-success tg-send-btn" type="button"><i class="fa fa-paper-plane"></i> <strong>{vtranslate('LBL_SEND', 'TelegramBot')}</strong></button>
					</div>
				</form>
			{/if}
		</div>
	</div>
</div>
{/strip}
