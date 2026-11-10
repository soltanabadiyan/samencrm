<?php
require_once 'modules/SamenWidgets/dashboards/Base.php';

class SamenWidgets_DueNow_Dashboard extends SamenWidgets_Base_Dashboard {
	protected function template() { return 'DueNow'; }
	protected function assignData($viewer, Vtiger_Request $request) { $viewer->assign('SW_DUE', SamenWidgets_Data::due(25)); }
}
