<?php
/* SamenWidgets – base of the dashboard widgets: header / footer of Vtiger's widget frame + contents template. */
require_once 'modules/SamenWidgets/lib/Data.php';

abstract class SamenWidgets_Base_Dashboard extends Vtiger_IndexAjax_View {

	abstract protected function template();
	abstract protected function assignData($viewer, Vtiger_Request $request);

	public function process(Vtiger_Request $request) {
		$user = Users_Record_Model::getCurrentUserModel();
		$viewer = $this->getViewer($request);
		$widget = Vtiger_Widget_Model::getInstance($request->get('linkid'), $user->getId());
		$viewer->assign('WIDGET', $widget);
		$viewer->assign('MODULE_NAME', 'SamenWidgets');
		$viewer->assign('SW_NAME', $request->get('name'));
		$this->assignData($viewer, $request);
		$tpl = $this->template();
		$viewer->assign('SW_CONTENTS', 'dashboards/' . $tpl . 'Contents.tpl');
		if ($request->get('content')) {
			$viewer->view('dashboards/' . $tpl . 'Contents.tpl', 'SamenWidgets');
		} else {
			$viewer->view('dashboards/Frame.tpl', 'SamenWidgets');
		}
	}
}

/** charts: one subclass per chart, $chart names the data set in SamenWidgets_Data::chart() */
abstract class SamenWidgets_Chart_Dashboard extends SamenWidgets_Base_Dashboard {
	protected $chart = '';
	protected function template() { return 'Chart'; }
	protected function assignData($viewer, Vtiger_Request $request) {
		$viewer->assign('SW_CHART', json_encode(SamenWidgets_Data::chart($this->chart), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP));
	}
}
