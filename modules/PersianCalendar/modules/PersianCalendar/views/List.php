<?php
/* PersianCalendar has no records: old menu links / bookmarks (view=List or Index) go to its settings page. */
require_once 'modules/PersianCalendar/PersianCalendar.php';

class PersianCalendar_List_View extends Vtiger_Index_View {
	function requiresPermission(Vtiger_Request $request) { return array(); }
	function checkPermission(Vtiger_Request $request) { return true; }
	function preProcess(Vtiger_Request $request, $display = true) {
		header('Location: ' . PersianCalendar::SETTINGS_LINK);
		exit;
	}
	function process(Vtiger_Request $request) {}
}
