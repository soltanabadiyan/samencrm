<?php
/* TelegramBot – "Telegram" tab on the Contact detail view: link status, deep links, conversation, quick reply. */
require_once 'modules/TelegramBot/lib/Access.php';

class TelegramBot_History_View extends Vtiger_IndexAjax_View {

	function requiresPermission(Vtiger_Request $request) { return array(); }
	function checkPermission(Vtiger_Request $request) {
		TelegramBot_Access::requireUse();
		if (!TelegramBot_Access::canSeeContact((int)$request->get('record'))) { TelegramBot_Access::deny(); }
		return true;
	}

	function process(Vtiger_Request $request) {
		$id = (int)$request->get('record');
		$viewer = $this->getViewer($request);
		$bots = TelegramBot_Store::bots(true);
		$links = array();
		foreach (TelegramBot_Store::linksForContact($id) as $l) { $l['linked_fmt'] = TelegramBot_Store::fmtTime($l['linked_at']); $links[$l['botid']] = $l; }
		$deep = array();
		foreach ($bots as $b) { $deep[$b['id']] = TelegramBot_Store::deepLink($b, $id); }
		$msgs = TelegramBot_Store::history($id, 300);
		foreach ($msgs as &$m) {
			$m['when'] = TelegramBot_Store::fmtTime($m['sent_at'] ? $m['sent_at'] : $m['created']);
			$m['is_image'] = $m['media_path'] !== '' && ($m['msg_type'] == 'photo' || strpos($m['media_mime'], 'image/') === 0);
		}
		unset($m);
		$viewer->assign('RECORD', $id);
		$viewer->assign('BOTS', $bots);
		$viewer->assign('LINKS', $links);
		$viewer->assign('DEEPLINKS', $deep);
		$viewer->assign('MESSAGES', $msgs);
		$viewer->assign('TEMPLATES', TelegramBot_Store::rows('SELECT id, title FROM vtiger_telegrambot_templates ORDER BY title'));
		$viewer->assign('MERGE_FIELDS', TelegramBot_Service::mergeFieldList());
		$viewer->assign('USER_DATE_FORMAT', Users_Record_Model::getCurrentUserModel()->get('date_format'));
		$viewer->view('History.tpl', $request->getModule());
	}
}
