<?php
require_once 'modules/SamenWidgets/dashboards/Base.php';

class SamenWidgets_MiniCalendar_Dashboard extends SamenWidgets_Base_Dashboard {
	protected function template() { return 'MiniCalendar'; }
	protected function assignData($viewer, Vtiger_Request $request) {
		$today = SamenWidgets_Data::today();
		$from = date('Y-m-d', strtotime($today . ' -75 day'));
		$to = date('Y-m-d', strtotime($today . ' +100 day'));
		$viewer->assign('SW_DAYS', json_encode(SamenWidgets_Data::activityDays($from, $to), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT));
		$viewer->assign('SW_TODAY', $today);
	}
}
