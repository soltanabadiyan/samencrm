<?php
/*+**********************************************************************************
 * SamenAcademy – help and training for SamenCRM (vtlib extension module, no records).
 *  1. "راهنما" button in the top bar of every page (HEADERSCRIPT help.js + HEADERCSS help.css): a side panel
 *     with what the page is for, step-by-step tasks, sales/marketing tips and an explanation of every field
 *     on the page (resources/help/pages.json, resources/help/fields.json).
 *  2. The academy (index.php?module=SamenAcademy&view=Index): courses and lessons (resources/content),
 *     annotated screenshots, workflow diagrams, checklists, formulas. Linked from the app menus
 *     (Marketing, Sales, Tools) and from the help panel.
 * Checklist progress is kept per user in the browser (localStorage).
 ************************************************************************************/
require_once 'vtlib/Vtiger/Module.php';
require_once 'vtlib/Vtiger/Link.php';

class SamenAcademy {

	const VERSION = '1.1.3';
	const RES = 'layouts/v7/modules/SamenAcademy/resources/';
	const APPS = array('MARKETING', 'SALES', 'TOOLS');

	static function registerLinks() {
		self::unregisterLinks();
		$tabid = getTabid('SamenAcademy');
		$v = '?v=' . self::VERSION;
		Vtiger_Link::addLink($tabid, 'HEADERCSS', 'SamenAcademyHelpCss', self::RES . 'help.css' . $v, '', 1);
		Vtiger_Link::addLink($tabid, 'HEADERSCRIPT', 'SamenAcademyHelpJs', self::RES . 'help.js' . $v, '', 3);
	}

	static function unregisterLinks() {
		global $adb;
		$adb->pquery("DELETE FROM vtiger_links WHERE tabid=? AND linktype IN ('HEADERSCRIPT','HEADERCSS')", array(getTabid('SamenAcademy')));
	}

	/** app menu entries (vtlib only knows the old parent tabs) */
	static function registerApps() {
		global $adb;
		$tabid = getTabid('SamenAcademy');
		$adb->pquery("UPDATE vtiger_tab SET parent='' WHERE tabid=?", array($tabid));
		foreach (self::APPS as $app) {
			$r = $adb->pquery('SELECT 1 FROM vtiger_app2tab WHERE tabid=? AND appname=?', array($tabid, $app));
			if ($adb->num_rows($r)) { continue; }
			$seq = $adb->query_result($adb->pquery('SELECT COALESCE(MAX(sequence),0)+1 s FROM vtiger_app2tab WHERE appname=?', array($app)), 0, 's');
			$adb->pquery('INSERT INTO vtiger_app2tab (tabid, appname, sequence, visible) VALUES (?,?,?,1)', array($tabid, $app, $seq));
		}
		if (function_exists('create_tab_data_file')) { create_tab_data_file(); }
	}

	static function unregisterApps() {
		global $adb;
		$adb->pquery('DELETE FROM vtiger_app2tab WHERE tabid=?', array(getTabid('SamenAcademy')));
	}

	/** every user may open the academy: give all profiles view access to this module */
	static function allowAllProfiles() {
		global $adb;
		$tabid = getTabid('SamenAcademy');
		$r = $adb->pquery('SELECT profileid FROM vtiger_profile', array());
		while ($row = $adb->fetch_array($r)) {
			$adb->pquery('DELETE FROM vtiger_profile2tab WHERE profileid=? AND tabid=?', array($row['profileid'], $tabid));
			$adb->pquery('INSERT INTO vtiger_profile2tab (profileid, tabid, permissions) VALUES (?,?,0)', array($row['profileid'], $tabid));
		}
		if (function_exists('create_tab_data_file')) { create_tab_data_file(); }
		$u = $adb->pquery("SELECT id FROM vtiger_users WHERE deleted=0", array());
		while ($row = $adb->fetch_array($u)) { createUserPrivilegesfile($row['id']); }
	}

	static function apply() {
		self::registerLinks();
		self::registerApps();
		self::allowAllProfiles();
	}

	function vtlib_handler($moduleName, $eventType) {
		if (in_array($eventType, array('module.postinstall', 'module.postupdate', 'module.enabled'))) {
			self::apply();
		} else if (in_array($eventType, array('module.disabled', 'module.preuninstall'))) {
			self::unregisterLinks();
			self::unregisterApps();
		}
	}
}
