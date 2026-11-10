{*<!-- TelegramBot – message composer (used by Index.tpl, SendModal.tpl, History.tpl). Variables: BOTS, TEMPLATES, MERGE_FIELDS, USER_DATE_FORMAT, COMPACT -->*}
{strip}
<div class="tg-compose">
	{if $BOTS|@count gt 1}
		<div class="form-group">
			<label class="control-label">{vtranslate('LBL_BOT', 'TelegramBot')}</label>
			<select name="botid" class="inputElement select2 tg-bot">
				{foreach item=B from=$BOTS}<option value="{$B.id}" {if $B.is_default}selected{/if}>{$B.title|escape:'html'} (@{$B.username|escape:'html'})</option>{/foreach}
			</select>
		</div>
	{elseif $BOTS|@count eq 1}
		<input type="hidden" name="botid" value="{$BOTS[0].id}" />
	{/if}
	<div class="row">
		<div class="col-sm-6 form-group">
			<label class="control-label">{vtranslate('LBL_TEMPLATE', 'TelegramBot')}</label>
			<select class="inputElement tg-template-pick">
				<option value="">{vtranslate('LBL_SELECT_TEMPLATE', 'TelegramBot')}</option>
				{foreach item=T from=$TEMPLATES}<option value="{$T.id}">{$T.title|escape:'html'}</option>{/foreach}
			</select>
		</div>
		<div class="col-sm-6 form-group">
			<label class="control-label">{vtranslate('LBL_MERGE_FIELD', 'TelegramBot')}</label>
			<select class="inputElement tg-merge-pick">
				<option value="">{vtranslate('LBL_INSERT_FIELD', 'TelegramBot')}</option>
				{foreach key=TOKEN item=LABEL from=$MERGE_FIELDS}<option value="{$TOKEN|escape:'html'}">{$LABEL|escape:'html'} – {$TOKEN|escape:'html'}</option>{/foreach}
			</select>
		</div>
	</div>
	<div class="form-group">
		<label class="control-label">{vtranslate('LBL_MESSAGE_TEXT', 'TelegramBot')}</label>
		<textarea name="body" class="form-control tg-body" rows="{if $COMPACT}3{else}6{/if}" dir="auto" placeholder="{vtranslate('LBL_BODY_PLACEHOLDER', 'TelegramBot')}"></textarea>
		<div class="help-block tg-counter"></div>
	</div>
	<div class="form-group tg-media">
		<label class="control-label">{vtranslate('LBL_ATTACHMENT', 'TelegramBot')}</label>
		<div>
			<label class="radio-inline"><input type="radio" name="media_source" value="" checked /> {vtranslate('LBL_NO_ATTACHMENT', 'TelegramBot')}</label>
			<label class="radio-inline"><input type="radio" name="media_source" value="upload" /> {vtranslate('LBL_UPLOAD_FILE', 'TelegramBot')}</label>
			<label class="radio-inline"><input type="radio" name="media_source" value="document" /> {vtranslate('LBL_FROM_DOCUMENTS', 'TelegramBot')}</label>
		</div>
		<div class="tg-media-upload hide" style="margin-top:8px">
			<input type="file" name="media_file" class="form-control" />
		</div>
		<div class="tg-media-document hide" style="margin-top:8px">
			<div class="input-group">
				<input type="text" class="form-control tg-doc-search" placeholder="{vtranslate('LBL_SEARCH_DOCUMENTS', 'TelegramBot')}" />
				<span class="input-group-addon"><i class="fa fa-search"></i></span>
			</div>
			<select name="docid" class="form-control tg-doc-select" style="margin-top:6px"><option value="">—</option></select>
		</div>
		<div class="tg-media-type hide" style="margin-top:8px">
			<label class="control-label">{vtranslate('LBL_SEND_AS', 'TelegramBot')}</label>
			<select name="media_type" class="inputElement">
				<option value="">{vtranslate('LBL_AUTO', 'TelegramBot')}</option>
				<option value="photo">{vtranslate('LBL_TYPE_photo', 'TelegramBot')}</option>
				<option value="document">{vtranslate('LBL_TYPE_document', 'TelegramBot')}</option>
				<option value="video">{vtranslate('LBL_TYPE_video', 'TelegramBot')}</option>
				<option value="audio">{vtranslate('LBL_TYPE_audio', 'TelegramBot')}</option>
			</select>
		</div>
	</div>
	<div class="form-group tg-when">
		<label class="control-label">{vtranslate('LBL_WHEN', 'TelegramBot')}</label>
		<div>
			<label class="radio-inline"><input type="radio" name="when" value="now" checked /> {vtranslate('LBL_SEND_NOW', 'TelegramBot')}</label>
			<label class="radio-inline"><input type="radio" name="when" value="later" /> {vtranslate('LBL_SCHEDULE', 'TelegramBot')}</label>
		</div>
		<div class="row tg-when-later hide" style="margin-top:8px">
			<div class="col-sm-6">
				<div class="input-group inputElement" style="margin-bottom:3px">
					<input type="text" name="send_date" class="dateField form-control" data-date-format="{$USER_DATE_FORMAT}" value="" autocomplete="off" />
					<span class="input-group-addon"><i class="fa fa-calendar"></i></span>
				</div>
			</div>
			<div class="col-sm-6">
				<input type="time" name="send_time" class="form-control" value="09:00" dir="ltr" />
			</div>
		</div>
	</div>
</div>
{/strip}
