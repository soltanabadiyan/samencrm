{*<!-- TelegramBot – header: تلگرام › page
   Module-specific header (Vtiger picks layouts/v7/modules/<Module>/ModuleHeader.tpl over the Vtiger one):
   the stock header assumes a record list (filter name, Add/Import buttons), which this module does not have. -->*}
{strip}
	<div class="col-sm-11 col-xs-10 padding0 module-action-bar clearfix coloredBorderTop">
		<div class="module-action-content clearfix {$MODULE}-module-action-content">
			<div class="col-lg-7 col-md-6 col-sm-7 col-xs-11 padding0 module-breadcrumb module-breadcrumb-{$REQ->get('view')} transitionsAllHalfSecond">
				<a title="{vtranslate('TelegramBot', 'TelegramBot')}" href="index.php?module=TelegramBot&view=Index&app=MARKETING"><h4 class="module-title pull-left">&nbsp;{vtranslate('TelegramBot', 'TelegramBot')}&nbsp;</h4></a>
				{if $REQ->get('view') eq 'Settings'}<p class="current-filter-name filter-name pull-left"><span class="fa fa-angle-right pull-left" aria-hidden="true"></span><a>&nbsp;&nbsp;{vtranslate('LBL_SETTINGS', 'TelegramBot')}&nbsp;&nbsp;</a></p>{/if}
			</div>
			<div class="col-lg-5 col-md-6 col-sm-5 col-xs-1 padding0 pull-right">
				<div id="appnav" class="navbar-right">
					{assign var=TG_USER value=Users_Record_Model::getCurrentUserModel()}
					{if $REQ->get('view') eq 'Settings'}
						<a class="btn btn-default module-buttons samen-head-btn" href="index.php?module=TelegramBot&view=Index&app=MARKETING"><i class="fa fa-paper-plane"></i>&nbsp;<span class="hidden-xs">{vtranslate('LBL_BULK_SEND', 'TelegramBot')}</span></a>
					{elseif $TG_USER->isAdminUser()}
						<a class="btn btn-default module-buttons samen-head-btn" href="index.php?module=TelegramBot&view=Settings&app=MARKETING"><i class="fa fa-cog"></i>&nbsp;<span class="hidden-xs">{vtranslate('LBL_SETTINGS', 'TelegramBot')}</span></a>
					{/if}
				</div>
			</div>
		</div>
	</div>
{/strip}
