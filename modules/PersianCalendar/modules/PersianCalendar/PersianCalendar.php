<?php
/*+**********************************************************************************
 * PersianCalendar – Jalali (Shamsi) dates and RTL layout for Persian (fa_ir) users.
 * Vtiger extension module (vtlib). SamenStudent.
 *
 * The module has no records. On install/enable it registers two global header links
 * (Vtiger_Link HEADERSCRIPT / HEADERCSS), which Vtiger_Header_View adds to every page:
 *   layouts/v7/modules/PersianCalendar/resources/PersianCalendar.js
 *   layouts/v7/modules/PersianCalendar/resources/PersianCalendar.css (picker + calendar)
 * RTL layout and fonts live in the SamenTheme custom skin since 1.1.0.
 * Disabling the module in Settings > Module Management removes the links again.
 * Since 1.2.0 it has a page in Settings > Extensions (status, users' calendar preferences)
 * and is no longer listed in the Tools app menu (it has no records to list).
 ************************************************************************************/
require_once 'vtlib/Vtiger/Module.php';
require_once 'vtlib/Vtiger/Link.php';

class PersianCalendar {

	const VERSION = '1.2.4';
	const SETTINGS_LINK = 'index.php?module=PersianCalendar&view=Settings&app=SETTINGS';
	const SETTINGS_LABEL = 'تقویم شمسی';
	const BASE = 'layouts/v7/modules/PersianCalendar/resources/';

	static function links() {
		$v = '?v=' . self::VERSION;
		return array(
			array('HEADERCSS', 'PersianCalendarCss', self::BASE . 'PersianCalendar.css' . $v),
			array('HEADERSCRIPT', 'PersianCalendarJs', self::BASE . 'PersianCalendar.js' . $v),
		);
	}

	static function registerLinks() {
		$tabid = getTabid('PersianCalendar');
		self::unregisterLinks();
		$seq = 1;
		foreach (self::links() as $l) {
			Vtiger_Link::addLink($tabid, $l[0], $l[1], $l[2], '', $seq++);
		}
	}

	static function unregisterLinks() {
		global $adb;
		$tabid = getTabid('PersianCalendar');
		// remove every version of our links (URLs carry ?v=...)
		$adb->pquery("DELETE FROM vtiger_links WHERE tabid=? AND linktype IN ('HEADERSCRIPT','HEADERCSS')", array($tabid));
	}

	static function registerSettings() {
		global $adb;
		self::unregisterSettings();
		$b = $adb->pquery("SELECT blockid FROM vtiger_settings_blocks WHERE label IN ('LBL_EXTENSIONS','LBL_OTHER_SETTINGS') ORDER BY FIELD(label,'LBL_EXTENSIONS','LBL_OTHER_SETTINGS') LIMIT 1", array());
		$blockid = $adb->num_rows($b) ? $adb->query_result($b, 0, 'blockid') : 4;
		$seq = $adb->query_result($adb->pquery('SELECT COALESCE(MAX(sequence),0)+1 s FROM vtiger_settings_field WHERE blockid=?', array($blockid)), 0, 's');
		$adb->pquery('INSERT INTO vtiger_settings_field (fieldid, blockid, name, iconpath, description, linkto, sequence, active, pinned) VALUES (?,?,?,?,?,?,?,0,0)',
			array($adb->getUniqueID('vtiger_settings_field'), $blockid, self::SETTINGS_LABEL, '', 'تاریخ‌های شمسی و تنظیمات تقویم کاربران', self::SETTINGS_LINK, $seq));
	}

	static function unregisterSettings() {
		global $adb;
		$adb->pquery('DELETE FROM vtiger_settings_field WHERE linkto LIKE ?', array('index.php?module=PersianCalendar&%'));
	}

	static function removeFromAppMenu() {
		global $adb;
		$tabid = getTabid('PersianCalendar');
		$adb->pquery('DELETE FROM vtiger_app2tab WHERE tabid=?', array($tabid));
		$adb->pquery("UPDATE vtiger_tab SET parent='' WHERE tabid=?", array($tabid));
		if (function_exists('create_tab_data_file')) { create_tab_data_file(); }
	}

	/** Persian calendar preferences (what the installer's --all-users sets) */
	static function persianPrefs() {
		return array('language' => 'fa_ir', 'dayoftheweek' => 'Saturday', 'time_zone' => 'Asia/Tehran', 'date_format' => 'yyyy-mm-dd');
	}

	static function users() {
		global $adb;
		$rows = array();
		$r = $adb->pquery("SELECT id, user_name, first_name, last_name, is_admin, language, dayoftheweek, time_zone, date_format FROM vtiger_users WHERE status='Active' AND deleted=0 ORDER BY user_name", array());
		while ($row = $adb->fetch_array($r)) {
			$ok = true;
			foreach (self::persianPrefs() as $k => $v) { if ($row[$k] !== $v) { $ok = false; } }
			$row['persian'] = $ok;
			$row['full_name'] = trim(decode_html($row['first_name']) . ' ' . decode_html($row['last_name']));
			$rows[] = $row;
		}
		return $rows;
	}

	static function applyToUsers($ids = null) {
		global $adb;
		$p = self::persianPrefs();
		$users = self::users();
		$n = 0;
		foreach ($users as $u) {
			if ($ids !== null && !in_array($u['id'], $ids)) { continue; }
			$adb->pquery('UPDATE vtiger_users SET language=?, dayoftheweek=?, time_zone=?, date_format=? WHERE id=?',
				array($p['language'], $p['dayoftheweek'], $p['time_zone'], $p['date_format'], $u['id']));
			createUserPrivilegesfile($u['id']);
			$n++;
		}
		return $n;
	}

	static function status() {
		global $adb;
		$links = array();
		$r = $adb->pquery("SELECT linktype, linkurl FROM vtiger_links WHERE tabid=? AND linktype IN ('HEADERSCRIPT','HEADERCSS')", array(getTabid('PersianCalendar')));
		while ($row = $adb->fetch_array($r)) { $links[] = $row['linktype'] . ' ' . $row['linkurl']; }
		$lang = $adb->pquery("SELECT active FROM vtiger_language WHERE prefix='fa_ir'", array());
		global $default_language;
		return array(
			'version' => self::VERSION,
			'links' => $links,
			'lang_ok' => $adb->num_rows($lang) && $adb->query_result($lang, 0, 'active') == 1,
			'default_language' => $default_language,
		);
	}

	static function apply() {
		self::registerLinks();
		self::registerSettings();
		self::removeFromAppMenu();
	}

	/**
	 * Invoked by vtlib on module events.
	 */
	function vtlib_handler($moduleName, $eventType) {
		if ($eventType == 'module.postinstall' || $eventType == 'module.postupdate' || $eventType == 'module.enabled') {
			self::apply();
		} else if ($eventType == 'module.disabled' || $eventType == 'module.preuninstall') {
			self::unregisterLinks();
			self::unregisterSettings();
		}
	}
}
