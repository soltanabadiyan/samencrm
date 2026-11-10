{*<!-- TelegramBot – send modal (Contact detail view button / Contacts list mass action) -->*}
{strip}
<div class="modal-dialog modal-lg tg-modal">
	<div class="modal-content">
		<div class="modal-header">
			<div class="clearfix">
				<div class="pull-right"><button type="button" class="close" aria-label="Close" data-dismiss="modal"><span aria-hidden="true" class="fa fa-close"></span></button></div>
				<h4 class="pull-left"><i class="fa fa-paper-plane"></i>&nbsp;
					{vtranslate('LBL_SEND_TELEGRAM', 'TelegramBot')}
					{if $RECORD}: {$CONTACT_NAME|escape:'html'}{/if}
				</h4>
			</div>
		</div>
		<form class="tg-send-form" enctype="multipart/form-data" onsubmit="return false;">
			<input type="hidden" name="module" value="TelegramBot" />
			<input type="hidden" name="action" value="Ajax" />
			<input type="hidden" name="mode" value="send" />
			{if $RECORD}
				<input type="hidden" name="target_type" value="ids" />
				<input type="hidden" name="record" value="{$RECORD}" />
			{else}
				<input type="hidden" name="target_type" value="list" />
				<input type="hidden" name="list" value="{$LIST_PARAMS|escape:'html'}" />
			{/if}
			<div class="modal-body">
				{if !$BOTS}
					<div class="alert alert-warning">{vtranslate('LBL_NO_BOT', 'TelegramBot')}</div>
				{else}
					{if $RECORD}
						{assign var=BOT0 value=$BOTS[0]}
						{if !$LINKS}
							<div class="alert alert-warning">{vtranslate('LBL_NOT_LINKED_HINT', 'TelegramBot')}</div>
						{else}
							{foreach item=L from=$LINKS}
								<div class="alert {if $L.optout || $L.blocked}alert-danger{else}alert-success{/if}" style="padding:6px 10px">
									<i class="fa fa-telegram"></i> {$L.bot_title|escape:'html'}: {$L.tg_name|escape:'html'} {if $L.tg_username}(@{$L.tg_username|escape:'html'}){/if}
									{if $L.optout} – {vtranslate('LBL_OPTED_OUT', 'TelegramBot')}{/if}
									{if $L.blocked} – {vtranslate('LBL_BLOCKED', 'TelegramBot')}{/if}
								</div>
							{/foreach}
						{/if}
					{else}
						<div class="alert alert-info tg-count-box">{vtranslate('LBL_COUNTING', 'TelegramBot')}</div>
						<div class="form-group">
							<label class="control-label">{vtranslate('LBL_CAMPAIGN_TITLE', 'TelegramBot')}</label>
							<input type="text" name="title" class="form-control" />
						</div>
					{/if}
					{include file="Compose.tpl"|vtemplate_path:'TelegramBot' COMPACT=false}
				{/if}
			</div>
			<div class="modal-footer">
				<center>
					{if $BOTS}
						<button class="btn btn-default tg-preview-btn" type="button"><i class="fa fa-eye"></i> {vtranslate('LBL_PREVIEW', 'TelegramBot')}</button>
						<button class="btn btn-success tg-send-btn" type="button"><i class="fa fa-paper-plane"></i> <strong>{vtranslate('LBL_SEND', 'TelegramBot')}</strong></button>
					{/if}
					<a href="#" class="cancelLink" type="reset" data-dismiss="modal">{vtranslate('LBL_CANCEL', 'Vtiger')}</a>
				</center>
			</div>
		</form>
	</div>
</div>
{/strip}
