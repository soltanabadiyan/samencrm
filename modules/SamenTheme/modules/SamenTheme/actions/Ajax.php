<?php
/* SamenTheme – admin actions from the settings page. */
require_once 'modules/SamenTheme/SamenTheme.php';

class SamenTheme_Ajax_Action extends Vtiger_Action_Controller {

	function requiresPermission(Vtiger_Request $request) { return array(); }

	function checkPermission(Vtiger_Request $request) {
		$user = Users_Record_Model::getCurrentUserModel();
		if (!$user || !$user->isAdminUser()) { throw new AppException(vtranslate('LBL_PERMISSION_DENIED')); }
		return true;
	}

	function process(Vtiger_Request $request) {
		$response = new Vtiger_Response();
		try {
			switch ($request->get('mode')) {
				case 'apply': SamenTheme::apply(); break;
				case 'logo': SamenTheme::setCompanyLogo(); break;
				default: throw new Exception('unknown mode');
			}
			$response->setResult(array('ok' => true));
		} catch (Exception $e) {
			$response->setError($e->getCode(), $e->getMessage());
		}
		$response->emit();
	}

	function validateRequest(Vtiger_Request $request) { $request->validateWriteAccess(); }
}
