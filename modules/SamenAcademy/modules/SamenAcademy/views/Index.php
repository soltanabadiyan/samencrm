<?php
/* SamenAcademy – the academy page (courses / lessons). Open to every logged-in user. */
class SamenAcademy_Index_View extends Vtiger_Index_View {

	function requiresPermission(Vtiger_Request $request) { return array(); }
	function checkPermission(Vtiger_Request $request) { return true; }

	function process(Vtiger_Request $request) {
		$viewer = $this->getViewer($request);
		$user = Users_Record_Model::getCurrentUserModel();
		$viewer->assign('ACADEMY_USER', $user->getId());
		$viewer->assign('ACADEMY_ADMIN', $user->isAdminUser());
		$viewer->assign('ACADEMY_LESSON', preg_replace('/[^a-z0-9\-]/', '', (string) $request->get('lesson')));
		$viewer->view('Index.tpl', $request->getModule());
	}

	function getHeaderCss(Vtiger_Request $request) {
		return array_merge(parent::getHeaderCss($request),
			$this->checkAndConvertCssStyles(array('~layouts/v7/modules/SamenAcademy/resources/academy.css')));
	}

	function getHeaderScripts(Vtiger_Request $request) {
		return array_merge(parent::getHeaderScripts($request),
			$this->checkAndConvertJsScripts(array('~layouts/v7/modules/SamenAcademy/resources/academy.js')));
	}
}
