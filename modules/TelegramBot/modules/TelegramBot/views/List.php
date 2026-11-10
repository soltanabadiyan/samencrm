<?php
/* The app menu links every module to view=List; TelegramBot has no record list, so go to its main page. */
class TelegramBot_List_View extends Vtiger_Index_View {
	function requiresPermission(Vtiger_Request $request) { return array(); }
	function checkPermission(Vtiger_Request $request) { return true; }
	function preProcess(Vtiger_Request $request, $display = true) {
		$app = $request->get('app') ? '&app=' . urlencode($request->get('app')) : '&app=MARKETING';
		header('Location: index.php?module=TelegramBot&view=Index' . $app);
		exit;
	}
	function process(Vtiger_Request $request) {}
}
