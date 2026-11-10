<?php
/*+**********************************************************************************
 * SamenWidgets – SamenCRM reminder bell and dashboard widgets (vtlib extension, no records).
 *  - HEADERSCRIPT/HEADERCSS: bell in the header (overdue + due-today tasks/events of the user, with a count) and
 *    Vtiger's reminder pop-ups (which covered the menu) collected into that bell instead of floating over the page
 *  - DASHBOARDWIDGET links (Home): due now, Jalali mini calendar, charts (Chart.js 4, bundled)
 *  - on first install every user's default dashboard tab gets the widgets
 ************************************************************************************/
require_once 'vtlib/Vtiger/Module.php';
require_once 'vtlib/Vtiger/Link.php';

class SamenWidgets {

	const VERSION = '1.1.1';
	// (a literal '?' inside SQL would be taken as a placeholder by PearDatabase – patterns go in as parameters)
	const LIKE = 'index.php?module=SamenWidgets&%';
	const RES = 'layouts/v7/modules/SamenWidgets/resources/';

	/** name => [label, sizex, sizey] in the order they are placed on dashboards */
	static function widgets() {
		return array(
			'DueNow' => array('کارهای بدون زمان باقی‌مانده', 1, 2),
			'MiniCalendar' => array('تقویم کوچک', 1, 2),
			'ChartLeadSource' => array('مشتریان بر اساس منبع سرنخ', 1, 2),
			'ChartWeek' => array('کارهای باز ۱۴ روز آینده', 2, 2),
			'ChartCountry' => array('مشتریان بر اساس کشور', 1, 2),
			'ChartTrend' => array('روند مخاطبان جدید و فعالیت‌ها', 2, 2),
			'ChartActivities' => array('وضعیت وظایف و رویدادها', 1, 2),
			'ChartPipeline' => array('قیف فرصت‌های فروش', 2, 2),
			'ChartCompleteness' => array('میزان تکمیل پرونده مشتریان', 1, 2),
			'ChartWon' => array('فروش موفق ۱۲ ماه اخیر', 2, 2),
			'ChartLeadStatus' => array('سرنخ‌ها بر اساس وضعیت', 1, 2),
			'ChartTickets' => array('درخواست‌های پشتیبانی بر اساس وضعیت', 2, 2),
		);
	}

	/** grid position of each widget (3 columns): due, calendar and a chart first, then the wide charts */
	static function layout() {
		return array(
			'DueNow' => array(1, 1), 'MiniCalendar' => array(1, 2), 'ChartLeadSource' => array(1, 3),
			'ChartWeek' => array(3, 1), 'ChartCountry' => array(3, 3),
			'ChartTrend' => array(5, 1), 'ChartActivities' => array(5, 3),
			'ChartPipeline' => array(7, 1), 'ChartCompleteness' => array(7, 3),
			'ChartWon' => array(9, 1), 'ChartLeadStatus' => array(9, 3),
			'ChartTickets' => array(11, 1),
		);
	}

	/** our widgets at the top of the tab (only those without a position yet), the other widgets below them */
	static function arrange($uid, $tab) {
		global $adb;
		$home = getTabid('Home');
		$layout = self::layout();
		$placed = 0;
		foreach ($layout as $name => $pos) {
			$adb->pquery("UPDATE vtiger_module_dashboard_widgets w JOIN vtiger_links l ON l.linkid=w.linkid
				SET w.position=? WHERE w.userid=? AND w.dashboardtabid=? AND l.tabid=? AND l.linkurl=? AND (w.position IS NULL OR w.position='' OR w.position='null')",
				array(json_encode(array('row' => (string) $pos[0], 'col' => (string) $pos[1])), $uid, $tab, $home, self::url($name)));
			$placed++;
		}
		// stock widgets without a position: rows below ours, three per row
		$r = $adb->pquery("SELECT w.id FROM vtiger_module_dashboard_widgets w JOIN vtiger_links l ON l.linkid=w.linkid
			WHERE w.userid=? AND w.dashboardtabid=? AND l.linkurl NOT LIKE ? AND (w.position IS NULL OR w.position='' OR w.position='null') ORDER BY w.id", array($uid, $tab, self::LIKE));
		$i = 0;
		while ($row = $adb->fetch_array($r)) {
			$pos = array('row' => (string) (13 + 2 * intdiv($i, 3)), 'col' => (string) (1 + $i % 3));
			$adb->pquery('UPDATE vtiger_module_dashboard_widgets SET position=? WHERE id=?', array(json_encode($pos), $row['id']));
			$i++;
		}
	}

	static function url($name) { return 'index.php?module=SamenWidgets&view=ShowWidget&name=' . $name; }

	static function registerLinks() {
		global $adb;
		$tabid = getTabid('SamenWidgets');
		$adb->pquery("DELETE FROM vtiger_links WHERE tabid=? AND linktype IN ('HEADERSCRIPT','HEADERCSS')", array($tabid));
		$v = '?v=' . self::VERSION;
		Vtiger_Link::addLink($tabid, 'HEADERCSS', 'SamenWidgetsCss', self::RES . 'samenwidgets.css' . $v, '', 1);
		Vtiger_Link::addLink($tabid, 'HEADERSCRIPT', 'SamenWidgetsJs', self::RES . 'samenwidgets.js' . $v, '', 4);
		$home = getTabid('Home');
		// widgets dropped in a newer version (e.g. 1.0.x domain charts): remove the link and the users' copies
		$keep = array_map(array(__CLASS__, 'url'), array_keys(self::widgets()));
		$r = $adb->pquery("SELECT linkid, linkurl FROM vtiger_links WHERE tabid=? AND linktype='DASHBOARDWIDGET' AND linkurl LIKE ?", array($home, self::LIKE));
		while ($row = $adb->fetch_array($r)) {
			if (in_array(decode_html($row['linkurl']), $keep, true)) { continue; }
			$adb->pquery('DELETE FROM vtiger_module_dashboard_widgets WHERE linkid=?', array($row['linkid']));
			$adb->pquery('DELETE FROM vtiger_links WHERE linkid=?', array($row['linkid']));
		}
		foreach (self::widgets() as $name => $w) {
			$r = $adb->pquery("SELECT linkid FROM vtiger_links WHERE tabid=? AND linktype='DASHBOARDWIDGET' AND linkurl=?", array($home, self::url($name)));
			if ($adb->num_rows($r)) {
				$adb->pquery('UPDATE vtiger_links SET linklabel=? WHERE linkid=?', array($w[0], $adb->query_result($r, 0, 'linkid')));
			} else {
				Vtiger_Link::addLink($home, 'DASHBOARDWIDGET', $w[0], self::url($name), '', 0);
			}
		}
	}

	/** widgets of this module on every user's default dashboard tab (only those not there yet) */
	static function placeOnDashboards() {
		global $adb;
		$home = getTabid('Home');
		$users = $adb->pquery("SELECT id FROM vtiger_users WHERE deleted=0 AND status='Active'", array());
		while ($u = $adb->fetch_array($users)) {
			$uid = (int) $u['id'];
			$t = $adb->pquery('SELECT id FROM vtiger_dashboard_tabs WHERE userid=? ORDER BY isdefault DESC, sequence ASC LIMIT 1', array($uid));
			if (!$adb->num_rows($t)) {
				$adb->pquery("INSERT INTO vtiger_dashboard_tabs (tabname, isdefault, sequence, appname, modulename, userid) VALUES ('My Dashboard',1,1,'','',?)", array($uid));
				$t = $adb->pquery('SELECT id FROM vtiger_dashboard_tabs WHERE userid=? ORDER BY id DESC LIMIT 1', array($uid));
			}
			$tab = (int) $adb->query_result($t, 0, 'id');
			$row = 1;
			$r = $adb->pquery('SELECT MAX(id) m FROM vtiger_module_dashboard_widgets WHERE userid=?', array($uid));
			$col = 1;
			foreach (self::widgets() as $name => $w) {
				$l = $adb->pquery("SELECT linkid FROM vtiger_links WHERE tabid=? AND linktype='DASHBOARDWIDGET' AND linkurl=?", array($home, self::url($name)));
				if (!$adb->num_rows($l)) { continue; }
				$linkid = (int) $adb->query_result($l, 0, 'linkid');
				$has = $adb->pquery('SELECT 1 FROM vtiger_module_dashboard_widgets WHERE userid=? AND linkid=?', array($uid, $linkid));
				if ($adb->num_rows($has)) { continue; }
				$adb->pquery('INSERT INTO vtiger_module_dashboard_widgets (linkid, userid, filterid, title, data, position, dashboardtabid, size) VALUES (?,?,NULL,NULL,NULL,NULL,?,?)',
					array($linkid, $uid, $tab, json_encode(array('sizex' => (string) $w[1], 'sizey' => (string) $w[2]))));
			}
			self::arrange($uid, $tab);
		}
	}

	/** every profile may use the widgets (the data itself is filtered by ownership) */
	static function allowAllProfiles() {
		global $adb;
		$tabid = getTabid('SamenWidgets');
		$r = $adb->pquery('SELECT profileid FROM vtiger_profile', array());
		while ($row = $adb->fetch_array($r)) {
			$adb->pquery('DELETE FROM vtiger_profile2tab WHERE profileid=? AND tabid=?', array($row['profileid'], $tabid));
			$adb->pquery('INSERT INTO vtiger_profile2tab (profileid, tabid, permissions) VALUES (?,?,0)', array($row['profileid'], $tabid));
		}
		if (function_exists('create_tab_data_file')) { create_tab_data_file(); }
		$u = $adb->pquery('SELECT id FROM vtiger_users WHERE deleted=0', array());
		while ($row = $adb->fetch_array($u)) { createUserPrivilegesfile($row['id']); }
	}

	static function unregister() {
		global $adb;
		$adb->pquery("DELETE FROM vtiger_links WHERE tabid=? AND linktype IN ('HEADERSCRIPT','HEADERCSS')", array(getTabid('SamenWidgets')));
		$adb->pquery('DELETE w FROM vtiger_module_dashboard_widgets w JOIN vtiger_links l ON l.linkid=w.linkid WHERE l.linkurl LIKE ?', array(self::LIKE));
		$adb->pquery("DELETE FROM vtiger_links WHERE linktype='DASHBOARDWIDGET' AND linkurl LIKE ?", array(self::LIKE));
	}

	function vtlib_handler($moduleName, $eventType) {
		if (in_array($eventType, array('module.postinstall', 'module.postupdate', 'module.enabled'))) {
			self::registerLinks();
			self::allowAllProfiles();
			self::placeOnDashboards();
		} else if (in_array($eventType, array('module.disabled', 'module.preuninstall'))) {
			self::unregister();
		}
	}
}
