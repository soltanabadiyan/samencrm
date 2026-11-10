{*<!-- SamenWidgets – Vtiger widget frame (header, contents, footer icons) -->*}
<div class="dashboardWidgetHeader">
	{include file="dashboards/WidgetHeader.tpl"|@vtemplate_path:'Vtiger'}
</div>
<div class="dashboardWidgetContent sw-content sw-{$SW_NAME|escape:'html'}">
	{include file=$SW_CONTENTS|@vtemplate_path:'SamenWidgets'}
</div>
<div class="widgeticons dashBoardWidgetFooter">
	<div class="footerIcons pull-right">
		{include file="dashboards/DashboardFooterIcons.tpl"|@vtemplate_path:'Vtiger'}
	</div>
</div>
