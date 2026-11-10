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
			self::versioned($this->checkAndConvertCssStyles(array('~layouts/v7/modules/SamenAcademy/resources/academy.css')), 'href'));
	}

	function getHeaderScripts(Vtiger_Request $request) {
		return array_merge(parent::getHeaderScripts($request),
			self::versioned($this->checkAndConvertJsScripts(array('~layouts/v7/modules/SamenAcademy/resources/academy.js')), 'src'));
	}

	/** Vtiger adds only ?v=<Vtiger version> – our own version makes browsers fetch the new files after an update */
	static function versioned($models, $attr) {
		require_once 'modules/SamenAcademy/SamenAcademy.php';
		foreach ($models as $m) { $m->set($attr, $m->get($attr) . '?sa=' . SamenAcademy::VERSION); }
		return $models;
	}
}
