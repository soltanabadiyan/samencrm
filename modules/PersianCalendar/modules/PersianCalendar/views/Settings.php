<?php
/* PersianCalendar – Settings > Extensions page (admins): status and users' calendar preferences. */
require_once 'modules/PersianCalendar/PersianCalendar.php';

class PersianCalendar_Settings_View extends Vtiger_Index_View {

	function requiresPermission(Vtiger_Request $request) { return array(); }

	function checkPermission(Vtiger_Request $request) {
		$user = Users_Record_Model::getCurrentUserModel();
		if (!$user || !$user->isAdminUser()) { throw new AppException(vtranslate('LBL_PERMISSION_DENIED')); }
		return true;
	}

	function process(Vtiger_Request $request) {
		$viewer = $this->getViewer($request);
		$users = PersianCalendar::users();
		$persian = 0;
		foreach ($users as $u) { if ($u['persian']) { $persian++; } }
		$viewer->assign('PC', PersianCalendar::status());
		$viewer->assign('USERS', $users);
		$viewer->assign('PERSIAN_USERS', $persian);
		$viewer->assign('TODAY', date('Y-m-d'));
		$viewer->assign('NOW', date('Y-m-d H:i'));
		$viewer->view('Settings.tpl', $request->getModule());
	}

	function getHeaderCss(Vtiger_Request $request) {
		return array_merge(parent::getHeaderCss($request),
			$this->checkAndConvertCssStyles(array('~layouts/v7/modules/PersianCalendar/resources/settings.css')));
	}

	function getHeaderScripts(Vtiger_Request $request) {
		return array_merge(parent::getHeaderScripts($request),
			$this->checkAndConvertJsScripts(array('~layouts/v7/modules/PersianCalendar/resources/settings.js')));
	}
}
