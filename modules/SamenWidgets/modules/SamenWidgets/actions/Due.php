<?php
/* SamenWidgets – JSON for the header bell: overdue / due-today tasks and events of the current user. */
require_once 'modules/SamenWidgets/lib/Data.php';

class SamenWidgets_Due_Action extends Vtiger_Action_Controller {
	function requiresPermission(Vtiger_Request $request) { return array(); }
	function checkPermission(Vtiger_Request $request) { return true; }   // only the user's own activities are returned
	function process(Vtiger_Request $request) {
		$response = new Vtiger_Response();
		$response->setResult(SamenWidgets_Data::due(40));
		$response->emit();
	}
	function validateRequest(Vtiger_Request $request) { $request->validateReadAccess(); }
}
