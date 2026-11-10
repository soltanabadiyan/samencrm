{*<!-- SamenTheme – header: تنظیمات › افزونه‌ها › page
   Module-specific header (Vtiger picks layouts/v7/modules/<Module>/ModuleHeader.tpl over the Vtiger one):
   the stock header assumes a record list (filter name, Add/Import buttons), which this module does not have. -->*}
{strip}
	<div class="col-sm-11 col-xs-10 padding0 module-action-bar clearfix coloredBorderTop">
		<div class="module-action-content clearfix {$MODULE}-module-action-content">
			<div class="col-lg-7 col-md-6 col-sm-7 col-xs-11 padding0 module-breadcrumb module-breadcrumb-{$REQ->get('view')} transitionsAllHalfSecond">
				<a title="{vtranslate('LBL_SETTINGS', 'Settings:Vtiger')}" href="index.php?module=Vtiger&parent=Settings&view=Index"><h4 class="module-title pull-left">&nbsp;{vtranslate('LBL_SETTINGS', 'Settings:Vtiger')}&nbsp;</h4></a>
				<p class="current-filter-name filter-name pull-left"><span class="fa fa-angle-right pull-left" aria-hidden="true"></span><a href="index.php?module=Vtiger&parent=Settings&view=Index">&nbsp;&nbsp;{vtranslate('LBL_EXTENSIONS', 'Settings:Vtiger')}&nbsp;&nbsp;</a></p>
				<p class="current-filter-name filter-name pull-left"><span class="fa fa-angle-right pull-left" aria-hidden="true"></span><a>&nbsp;&nbsp;{vtranslate('LBL_ST_TITLE', 'SamenTheme')}&nbsp;&nbsp;</a></p>
			</div>
			<div class="col-lg-5 col-md-6 col-sm-5 col-xs-1 padding0 pull-right">
				<div id="appnav" class="navbar-right">

				</div>
			</div>
		</div>
	</div>
{/strip}
