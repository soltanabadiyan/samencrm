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
 ************************************************************************************/
require_once 'vtlib/Vtiger/Module.php';
require_once 'vtlib/Vtiger/Link.php';

class PersianCalendar {

	const VERSION = '1.1.0';
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

	/**
	 * Invoked by vtlib on module events.
	 */
	function vtlib_handler($moduleName, $eventType) {
		if ($eventType == 'module.postinstall' || $eventType == 'module.postupdate' || $eventType == 'module.enabled') {
			self::registerLinks();
		} else if ($eventType == 'module.disabled' || $eventType == 'module.preuninstall') {
			self::unregisterLinks();
		}
	}
}
