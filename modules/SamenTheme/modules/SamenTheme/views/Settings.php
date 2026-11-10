<?php
/* SamenTheme – Settings > Extensions page (admins): theme status and "apply again". */
require_once 'modules/SamenTheme/SamenTheme.php';

class SamenTheme_Settings_View extends Vtiger_Index_View {

	function requiresPermission(Vtiger_Request $request) { return array(); }

	function checkPermission(Vtiger_Request $request) {
		$user = Users_Record_Model::getCurrentUserModel();
		if (!$user || !$user->isAdminUser()) { throw new AppException(vtranslate('LBL_PERMISSION_DENIED')); }
		return true;
	}

	function process(Vtiger_Request $request) {
		$viewer = $this->getViewer($request);
		$viewer->assign('ST', SamenTheme::status());
		$viewer->assign('PALETTE', array(
			array('#13606F', 'فیروزه‌ای ثامن'), array('#0B3943', 'فیروزه‌ای تیره'), array('#7D4F2E', 'قهوه‌ای'),
			array('#D99058', 'مسی'), array('#A3C2E0', 'آسمانی'), array('#F4F7FB', 'زمینه'), array('#121721', 'متن'),
		));
		$viewer->view('Settings.tpl', $request->getModule());
	}

	function getHeaderCss(Vtiger_Request $request) {
		return array_merge(parent::getHeaderCss($request),
			$this->checkAndConvertCssStyles(array('~layouts/v7/modules/SamenTheme/resources/settings.css')));
	}

	function getHeaderScripts(Vtiger_Request $request) {
		return array_merge(parent::getHeaderScripts($request),
			$this->checkAndConvertJsScripts(array('~layouts/v7/modules/SamenTheme/resources/settings.js')));
	}
}
