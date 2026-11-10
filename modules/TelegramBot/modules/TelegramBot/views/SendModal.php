<?php
/* TelegramBot – compose modal for one contact (record=...) or the selection of the Contacts list view (list=JSON). */
require_once 'modules/TelegramBot/lib/Access.php';

class TelegramBot_SendModal_View extends Vtiger_IndexAjax_View {

	function requiresPermission(Vtiger_Request $request) { return array(); }
	function checkPermission(Vtiger_Request $request) {
		TelegramBot_Access::requireUse();
		$id = (int)$request->get('record');
		if ($id && !TelegramBot_Access::canSeeContact($id)) { TelegramBot_Access::deny(); }
		return true;
	}

	function process(Vtiger_Request $request) {
		$viewer = $this->getViewer($request);
		$id = (int)$request->get('record');
		$viewer->assign('RECORD', $id);
		$viewer->assign('LIST_PARAMS', $request->get('list') ? (string)$request->getRaw('list') : '');
		$bots = TelegramBot_Store::bots(true);
		$viewer->assign('BOTS', $bots);
		$viewer->assign('TEMPLATES', TelegramBot_Store::rows('SELECT * FROM vtiger_telegrambot_templates ORDER BY title'));
		$viewer->assign('MERGE_FIELDS', TelegramBot_Service::mergeFieldList());
		$viewer->assign('USER_DATE_FORMAT', Users_Record_Model::getCurrentUserModel()->get('date_format'));
		if ($id) {
			$data = TelegramBot_Service::contactsData(array($id));
			$viewer->assign('CONTACT_NAME', isset($data[$id]) ? $data[$id]['fullname'] : '');
			$links = array();
			foreach (TelegramBot_Store::linksForContact($id) as $l) { $links[$l['botid']] = $l; }
			$viewer->assign('LINKS', $links);
		}
		$viewer->view('SendModal.tpl', $request->getModule());
	}
}
