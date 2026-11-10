{*<!-- SamenAcademy – header: آکادمی SamenCRM (no record list, so not the stock header) -->*}
{strip}
	<div class="col-sm-11 col-xs-10 padding0 module-action-bar clearfix coloredBorderTop">
		<div class="module-action-content clearfix {$MODULE}-module-action-content">
			<div class="col-lg-7 col-md-6 col-sm-7 col-xs-11 padding0 module-breadcrumb module-breadcrumb-{$REQ->get('view')} transitionsAllHalfSecond">
				<a title="{vtranslate('SamenAcademy', 'SamenAcademy')}" href="index.php?module=SamenAcademy&view=Index"><h4 class="module-title pull-left">&nbsp;{vtranslate('SamenAcademy', 'SamenAcademy')}&nbsp;</h4></a>
			</div>
			<div class="col-lg-5 col-md-6 col-sm-5 col-xs-1 padding0 pull-right">
				<div id="appnav" class="navbar-right"></div>
			</div>
		</div>
	</div>
{/strip}
