<?php
/* The app menu links modules to view=List; the academy has no records – open the academy page. */
class SamenAcademy_List_View extends Vtiger_Index_View {
	function requiresPermission(Vtiger_Request $request) { return array(); }
	function checkPermission(Vtiger_Request $request) { return true; }
	function preProcess(Vtiger_Request $request, $display = true) {
		$app = preg_replace('/[^A-Z_]/', '', (string) $request->get('app'));
		header('Location: index.php?module=SamenAcademy&view=Index' . ($app ? '&app=' . $app : ''));
		exit;
	}
	function process(Vtiger_Request $request) {}
}
