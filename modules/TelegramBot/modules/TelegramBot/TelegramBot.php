<?php
/*+**********************************************************************************
 * TelegramBot – Telegram Bot API messaging for Vtiger CRM 7/8 (SamenCRM).
 * Extension module (vtlib, no records of its own). Data lives in vtiger_telegrambot_* tables.
 *
 * On install / update / enable it (idempotently):
 *   - creates its tables (TelegramBot_Store::createTables)
 *   - registers global HEADERSCRIPT / HEADERCSS links (TelegramBot.js / .css)
 *   - adds "Send Telegram" to the Contacts detail view (DETAILVIEWBASIC) and list view mass actions
 *   - registers cron tasks  cron/modules/TelegramBot/Worker.service (every run, ~60 s)
 *                          cron/modules/TelegramBot/Birthday.service (every 15 min, sends once a day)
 *   - adds Settings > Extensions > Telegram
 * Disable / uninstall removes links, cron tasks and the settings entry (tables are kept).
 ************************************************************************************/
require_once 'vtlib/Vtiger/Module.php';
require_once 'vtlib/Vtiger/Link.php';
require_once 'vtlib/Vtiger/Cron.php';
require_once 'modules/TelegramBot/lib/Store.php';

class TelegramBot {

	const VERSION = '1.0.1';
	const RES = 'layouts/v7/modules/TelegramBot/resources/';
	const SEND_LABEL = 'ارسال تلگرام';
	const SETTINGS_LABEL = 'ربات تلگرام';

	static function crons() {
		return array(
			array('TelegramBot Worker', 'cron/modules/TelegramBot/Worker.service', 60,
				'Telegram: receive updates (getUpdates), send queued / scheduled messages'),
			array('TelegramBot Birthdays', 'cron/modules/TelegramBot/Birthday.service', 900,
				'Telegram: queue birthday messages once a day'),
		);
	}

	static function install() {
		TelegramBot_Store::createTables();
		self::registerLinks();
		self::registerCrons();
		self::registerSettings();
		self::registerApp();
		$dir = TelegramBot_Store::storageDir();
		if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
	}

	static function uninstallHooks() {
		self::unregisterLinks();
		foreach (self::crons() as $c) { Vtiger_Cron::deregister($c[0]); }
		global $adb;
		$adb->pquery('DELETE FROM vtiger_settings_field WHERE linkto LIKE ?', array('index.php?module=TelegramBot&%'));
	}

	static function registerLinks() {
		self::unregisterLinks();
		$v = '?v=' . self::VERSION;
		$tabid = getTabid('TelegramBot');
		Vtiger_Link::addLink($tabid, 'HEADERCSS', 'TelegramBotCss', self::RES . 'TelegramBot.css' . $v, '', 1);
		Vtiger_Link::addLink($tabid, 'HEADERSCRIPT', 'TelegramBotJs', self::RES . 'TelegramBot.js' . $v, '', 2);
		$contacts = getTabid('Contacts');
		Vtiger_Link::addLink($contacts, 'DETAILVIEWBASIC', self::SEND_LABEL, 'javascript:TelegramBot.openSend()', '', 50);
		Vtiger_Link::addLink($contacts, 'LISTVIEWMASSACTION', self::SEND_LABEL, 'javascript:TelegramBot.massSend()', '', 50);
	}

	static function unregisterLinks() {
		global $adb;
		$adb->pquery("DELETE FROM vtiger_links WHERE tabid=? AND linktype IN ('HEADERSCRIPT','HEADERCSS')", array(getTabid('TelegramBot')));
		$adb->pquery("DELETE FROM vtiger_links WHERE tabid=? AND linkurl LIKE 'javascript:TelegramBot.%'", array(getTabid('Contacts')));
	}

	static function registerCrons() {
		foreach (self::crons() as $c) {
			if (!Vtiger_Cron::getInstance($c[0])) {
				Vtiger_Cron::register($c[0], $c[1], $c[2], 'TelegramBot', Vtiger_Cron::$STATUS_ENABLED, 0, $c[3]);
			} else {
				global $adb;
				$adb->pquery('UPDATE vtiger_cron_task SET handler_file=?, status=IF(status=0,1,status) WHERE name=?', array($c[1], $c[0]));
			}
		}
	}

	static function registerSettings() {
		global $adb;
		$link = 'index.php?module=TelegramBot&view=Settings';
		$r = $adb->pquery('SELECT fieldid FROM vtiger_settings_field WHERE linkto=?', array($link));
		if ($adb->num_rows($r)) { return; }
		$b = $adb->pquery("SELECT blockid FROM vtiger_settings_blocks WHERE label IN ('LBL_EXTENSIONS','LBL_INTEGRATION','LBL_OTHER_SETTINGS') ORDER BY FIELD(label,'LBL_EXTENSIONS','LBL_INTEGRATION','LBL_OTHER_SETTINGS') LIMIT 1", array());
		$blockid = $adb->num_rows($b) ? $adb->query_result($b, 0, 'blockid') : 4;
		$seq = $adb->query_result($adb->pquery('SELECT COALESCE(MAX(sequence),0)+1 s FROM vtiger_settings_field WHERE blockid=?', array($blockid)), 0, 's');
		$fieldid = $adb->getUniqueID('vtiger_settings_field');
		$adb->pquery('INSERT INTO vtiger_settings_field (fieldid, blockid, name, iconpath, description, linkto, sequence, active, pinned) VALUES (?,?,?,?,?,?,?,0,0)',
			array($fieldid, $blockid, self::SETTINGS_LABEL, '', 'Telegram Bot API', $link, $seq));
	}

	/** Make sure the module is in the MARKETING app menu (vtlib only knows the old parent tabs). */
	static function registerApp() {
		global $adb;
		$tabid = getTabid('TelegramBot');
		$r = $adb->pquery('SELECT 1 FROM vtiger_app2tab WHERE tabid=?', array($tabid));
		if ($adb->num_rows($r)) { return; }
		$seq = $adb->query_result($adb->pquery("SELECT COALESCE(MAX(sequence),0)+1 s FROM vtiger_app2tab WHERE appname='MARKETING'", array()), 0, 's');
		$adb->pquery("INSERT INTO vtiger_app2tab (tabid, appname, sequence, visible) VALUES (?, 'MARKETING', ?, 1)", array($tabid, $seq));
	}

	function vtlib_handler($moduleName, $eventType) {
		if (in_array($eventType, array('module.postinstall', 'module.postupdate', 'module.enabled'))) {
			self::install();
		} else if (in_array($eventType, array('module.disabled', 'module.preuninstall'))) {
			self::uninstallHooks();
		}
	}
}
