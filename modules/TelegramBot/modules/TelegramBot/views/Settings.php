<?php
/* TelegramBot – settings (admins): bot tokens, proxy / API base, texts, birthday messages, rate limits. */
require_once 'modules/TelegramBot/lib/Access.php';

class TelegramBot_Settings_View extends Vtiger_Index_View {

	function requiresPermission(Vtiger_Request $request) { return array(); }
	function checkPermission(Vtiger_Request $request) { TelegramBot_Access::requireAdmin(); return true; }

	function process(Vtiger_Request $request) {
		$viewer = $this->getViewer($request);
		$bots = TelegramBot_Store::bots();
		foreach ($bots as &$b) {
			$b['token_masked'] = TelegramBot_Store::maskToken($b['token']);
			unset($b['token']);
			$b['last_poll_fmt'] = TelegramBot_Store::fmtTime($b['last_poll']);
		}
		unset($b);
		$viewer->assign('BOTS', $bots);
		$s = TelegramBot_Store::settings(true);
		$viewer->assign('S', $s);
		$viewer->assign('MERGE_FIELDS', TelegramBot_Service::mergeFieldList());
		$viewer->assign('WORKER_LAST', TelegramBot_Store::fmtTime($s['worker_last_run']));
		$viewer->assign('WORKER_RESULT', $s['worker_last_result']);
		$cron = TelegramBot_Store::rows("SELECT name, frequency, status, laststart, lastend FROM vtiger_cron_task WHERE module='TelegramBot' ORDER BY id");
		foreach ($cron as &$c) { $c['laststart_fmt'] = TelegramBot_Store::fmtTime($c['laststart']); }
		unset($c);
		$viewer->assign('CRONS', $cron);
		$viewer->view('Settings.tpl', $request->getModule());
	}
}
