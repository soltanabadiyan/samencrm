<?php
/* TelegramBot – main page: bulk compose, campaigns / schedules, message log, templates. */
require_once 'modules/TelegramBot/lib/Access.php';

class TelegramBot_Index_View extends Vtiger_Index_View {

	function requiresPermission(Vtiger_Request $request) { return array(); }
	function checkPermission(Vtiger_Request $request) { TelegramBot_Access::requireUse(); return true; }

	function process(Vtiger_Request $request) {
		$viewer = $this->getViewer($request);
		$module = $request->getModule();
		$tab = $request->get('tab') ? $request->get('tab') : 'compose';
		$viewer->assign('TAB', $tab);
		$viewer->assign('IS_ADMIN', TelegramBot_Access::isAdmin());
		$viewer->assign('BOTS', TelegramBot_Store::bots(true));
		$viewer->assign('TEMPLATES', TelegramBot_Store::rows('SELECT * FROM vtiger_telegrambot_templates ORDER BY title'));
		$viewer->assign('MERGE_FIELDS', TelegramBot_Service::mergeFieldList());
		$viewer->assign('TAGS', TelegramBot_Service::tags());
		$viewer->assign('GROUPS', TelegramBot_Service::groups());
		$viewer->assign('FILTERS', TelegramBot_Service::filters());
		$viewer->assign('PICKLISTS', TelegramBot_Service::picklistFields());
		$viewer->assign('MISSING_ITEMS', TelegramBot_Service::missingItems());
		$viewer->assign('USER_DATE_FORMAT', Users_Record_Model::getCurrentUserModel()->get('date_format'));

		$campaigns = TelegramBot_Store::rows('SELECT c.*, b.username bot_username FROM vtiger_telegrambot_campaigns c LEFT JOIN vtiger_telegrambot_bots b ON b.id=c.botid ORDER BY c.id DESC LIMIT 100');
		$stats = TelegramBot_Service::campaignStats(array_map(function ($c) { return $c['id']; }, $campaigns));
		foreach ($campaigns as &$c) {
			$c['stats'] = isset($stats[$c['id']]) ? $stats[$c['id']] : array();
			$c['when'] = TelegramBot_Store::fmtTime($c['scheduled_at']);
		}
		unset($c);
		$viewer->assign('CAMPAIGNS', $campaigns);

		$where = array('1=1'); $params = array();
		$fStatus = $request->get('status'); $fDir = $request->get('direction');
		if ($fStatus) { $where[] = 'm.status=?'; $params[] = $fStatus; }
		if ($fDir) { $where[] = 'm.direction=?'; $params[] = $fDir; }
		if ($request->get('unlinked')) { $where[] = 'm.contactid=0'; }
		$messages = TelegramBot_Store::rows('SELECT m.*, cd.firstname, cd.lastname, b.username bot_username FROM vtiger_telegrambot_messages m
			LEFT JOIN vtiger_contactdetails cd ON cd.contactid=m.contactid LEFT JOIN vtiger_telegrambot_bots b ON b.id=m.botid
			WHERE ' . implode(' AND ', $where) . ' ORDER BY m.id DESC LIMIT 200', $params);
		foreach ($messages as &$m) { $m['when'] = TelegramBot_Store::fmtTime($m['sent_at'] ? $m['sent_at'] : $m['created']); }
		unset($m);
		$viewer->assign('MESSAGES', $messages);
		$viewer->assign('F_STATUS', $fStatus);
		$viewer->assign('F_DIRECTION', $fDir);
		$viewer->assign('F_UNLINKED', $request->get('unlinked'));

		$q = array();
		foreach (TelegramBot_Store::rows("SELECT status, COUNT(*) n FROM vtiger_telegrambot_messages WHERE direction='out' GROUP BY status") as $r) { $q[$r['status']] = $r['n']; }
		$viewer->assign('QUEUE', $q);
		$l = TelegramBot_Store::row('SELECT COUNT(DISTINCT contactid) n FROM vtiger_telegrambot_links WHERE optout=0 AND blocked=0');
		$viewer->assign('LINKED_COUNT', $l['n']);
		$viewer->assign('WORKER_LAST', TelegramBot_Store::fmtTime(TelegramBot_Store::get('worker_last_run')));
		$viewer->assign('WORKER_RESULT', TelegramBot_Store::get('worker_last_result'));
		$viewer->view('Index.tpl', $module);
	}
}
