<?php
/*+**********************************************************************************
 * SamenWidgets – data for the reminder bell and the dashboard widgets.
 * Admins see the whole CRM; other users only records they own (directly or through their groups).
 ************************************************************************************/
class SamenWidgets_Data {

	/** ids that may own the current user's records (user + groups) */
	static function ownerIds() {
		global $adb;
		$user = Users_Record_Model::getCurrentUserModel();
		$ids = array((int) $user->getId());
		$r = $adb->pquery('SELECT groupid FROM vtiger_users2group WHERE userid=?', array($user->getId()));
		while ($row = $adb->fetch_array($r)) { $ids[] = (int) $row['groupid']; }
		return $ids;
	}

	/** "AND e.smownerid IN (...)" for non-admins, "" for admins (charts) */
	static function ownerSql($alias = 'e', $always = false) {
		$user = Users_Record_Model::getCurrentUserModel();
		if (!$always && $user->isAdminUser()) { return ''; }
		return ' AND ' . $alias . '.smownerid IN (' . implode(',', array_map('intval', self::ownerIds())) . ')';
	}

	/** today in the user's time zone (Y-m-d) and "now" in UTC as stored by Vtiger */
	static function today() {
		$d = new DateTime('now', new DateTimeZone(Users_Record_Model::getCurrentUserModel()->get('time_zone') ?: 'Asia/Tehran'));
		return $d->format('Y-m-d');
	}

	/**
	 * Open tasks / events of the current user whose time is up: overdue, or due today.
	 * Tasks: due date; events: start date+time. Returns ['overdue' => [...], 'today' => [...], 'count' => n].
	 */
	static function due($limit = 60) {
		global $adb;
		$today = self::today();
		$nowUtc = gmdate('Y-m-d H:i:s');
		$sql = "SELECT a.activityid, a.subject, a.activitytype, a.date_start, a.time_start, a.due_date, a.time_end, a.priority,
				(SELECT CONCAT(TRIM(CONCAT(IFNULL(c.firstname,''),' ',IFNULL(c.lastname,'')))) FROM vtiger_cntactivityrel r
					JOIN vtiger_contactdetails c ON c.contactid=r.contactid WHERE r.activityid=a.activityid LIMIT 1) AS contact,
				CASE WHEN a.activitytype='Task' THEN a.due_date ELSE a.date_start END AS deadline_date,
				CASE WHEN a.activitytype='Task' THEN CONCAT(a.due_date,' 23:59:59') ELSE CONCAT(a.date_start,' ',IFNULL(a.time_start,'00:00:00')) END AS deadline
			FROM vtiger_activity a JOIN vtiger_crmentity e ON e.crmid=a.activityid AND e.deleted=0
			WHERE a.activitytype <> 'Emails'
				AND ((a.activitytype='Task' AND IFNULL(a.status,'') NOT IN ('Completed','Deferred'))
					OR (a.activitytype<>'Task' AND IFNULL(a.eventstatus,'') NOT IN ('Held','Not Held')))
				AND (CASE WHEN a.activitytype='Task' THEN a.due_date ELSE a.date_start END) <= ?" . self::ownerSql('e', true) . "
			ORDER BY deadline ASC";
		$r = $adb->pquery($sql, array($today));
		$out = array('overdue' => array(), 'today' => array(), 'count' => 0, 'overdue_count' => 0, 'today_count' => 0);
		while ($row = $adb->fetch_array($r)) {
			$overdue = $row['deadline_date'] < $today || ($row['activitytype'] !== 'Task' && $row['deadline'] < $nowUtc);
			$key = $overdue ? 'overdue' : 'today';
			$out[$key . '_count']++;
			if (count($out[$key]) < $limit) {
				$out[$key][] = array(
					'id' => (int) $row['activityid'],
					'subject' => decode_html($row['subject']),
					'type' => $row['activitytype'] === 'Task' ? 'task' : 'event',
					'date' => $row['deadline_date'],
					'time' => $row['activitytype'] === 'Task' ? '' : substr((string) $row['time_start'], 0, 5),
					'priority' => $row['priority'],
					'contact' => decode_html((string) $row['contact']),
				);
			}
		}
		$out['count'] = $out['overdue_count'] + $out['today_count'];
		return $out;
	}

	/** number of open activities per day (Y-m-d => n) in a date range, for the mini calendar */
	static function activityDays($from, $to) {
		global $adb;
		$sql = "SELECT CASE WHEN a.activitytype='Task' THEN a.due_date ELSE a.date_start END AS d, COUNT(*) n
			FROM vtiger_activity a JOIN vtiger_crmentity e ON e.crmid=a.activityid AND e.deleted=0
			WHERE a.activitytype <> 'Emails'
				AND ((a.activitytype='Task' AND IFNULL(a.status,'') NOT IN ('Completed','Deferred'))
					OR (a.activitytype<>'Task' AND IFNULL(a.eventstatus,'') NOT IN ('Held','Not Held')))
				AND (CASE WHEN a.activitytype='Task' THEN a.due_date ELSE a.date_start END) BETWEEN ? AND ?" . self::ownerSql('e', true) . "
			GROUP BY d";
		$r = $adb->pquery($sql, array($from, $to));
		$out = array();
		while ($row = $adb->fetch_array($r)) { $out[$row['d']] = (int) $row['n']; }
		return $out;
	}

	/** label => count for a contacts column ("" grouped as $empty), top $limit + "سایر" */
	static function contactsBy($expr, $join, $limit = 8, $empty = 'نامشخص', $map = array()) {
		global $adb;
		$sql = "SELECT $expr AS k, COUNT(*) n FROM vtiger_contactdetails cd
			JOIN vtiger_crmentity e ON e.crmid=cd.contactid AND e.deleted=0 $join
			WHERE 1=1" . self::ownerSql() . " GROUP BY k ORDER BY n DESC";
		$r = $adb->pquery($sql, array());
		$rows = array();
		while ($row = $adb->fetch_array($r)) {
			$k = trim(decode_html((string) $row['k']));
			$k = $k === '' ? $empty : (isset($map[$k]) ? $map[$k] : $k);
			$rows[$k] = (isset($rows[$k]) ? $rows[$k] : 0) + (int) $row['n'];
		}
		arsort($rows);
		if ($limit && count($rows) > $limit) {
			$top = array_slice($rows, 0, $limit, true);
			$top['سایر'] = array_sum(array_slice($rows, $limit, null, true)) + (isset($top['سایر']) ? $top['سایر'] : 0);
			$rows = $top;
		}
		return $rows;
	}

	/** 'Y-m' => Jalali label for the last 12 months, oldest first */
	static function lastMonths() {
		$months = array();
		$now = new DateTime('first day of this month');
		for ($i = 11; $i >= 0; $i--) {
			$d = (clone $now)->modify("-$i month");
			$months[$d->format('Y-m')] = self::jalaliMonthLabel((int) $d->format('Y'), (int) $d->format('n'));
		}
		return $months;
	}

	/** record count per value of $expr over $from (which joins vtiger_crmentity as e), largest first, the rest as «سایر» */
	static function countBy($from, $expr, $limit = 0) {
		global $adb;
		$r = $adb->pquery("SELECT $expr AS k, COUNT(*) n FROM $from WHERE 1=1" . self::ownerSql() . ' GROUP BY k ORDER BY n DESC', array());
		$rows = array();
		while ($row = $adb->fetch_array($r)) {
			$k = trim(decode_html((string) $row['k']));
			$k = $k === '' ? 'نامشخص' : $k;
			$rows[$k] = (isset($rows[$k]) ? $rows[$k] : 0) + (int) $row['n'];
		}
		if ($limit && count($rows) > $limit) {
			$top = array_slice($rows, 0, $limit, true);
			$top['سایر'] = array_sum(array_slice($rows, $limit, null, true));
			$rows = $top;
		}
		return $rows;
	}

	/** picklist values in the user's language (merging values that translate the same) */
	static function translated($rows, $module) {
		$out = array();
		foreach ($rows as $k => $n) {
			$t = ($k === 'نامشخص' || $k === 'سایر') ? $k : vtranslate($k, $module);
			$out[$t] = (isset($out[$t]) ? $out[$t] : 0) + $n;
		}
		return $out;
	}

	static function hasColumn($table, $column) {
		global $adb;
		$r = $adb->pquery('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=?', array($table, $column));
		return $adb->num_rows($r) > 0;
	}

	static function chart($name) {
		global $adb;
		$cf = ' LEFT JOIN vtiger_contactscf cf ON cf.contactid=cd.contactid';
		switch ($name) {
			case 'leadsource':
				return array('type' => 'doughnut', 'data' => self::translated(self::contactsBy('cs.leadsource', ' LEFT JOIN vtiger_contactsubdetails cs ON cs.contactsubscriptionid=cd.contactid', 6), 'Contacts'));
			case 'country':
				return array('type' => 'pie', 'data' => self::contactsBy('ca.mailingcountry', ' LEFT JOIN vtiger_contactaddress ca ON ca.contactaddressid=cd.contactid', 6));
			case 'pipeline':
				$d = self::countBy("vtiger_potential t JOIN vtiger_crmentity e ON e.crmid=t.potentialid AND e.deleted=0", 't.sales_stage');
				$order = array('Prospecting', 'Qualification', 'Needs Analysis', 'Value Proposition', 'Id. Decision Makers', 'Perception Analysis',
					'Proposal or Price Quote', 'Negotiation or Review', 'Closed Won', 'Closed Lost');
				$sorted = array();
				foreach ($order as $k) { if (isset($d[$k])) { $sorted[$k] = $d[$k]; unset($d[$k]); } }
				return array('type' => 'hbar', 'data' => self::translated($sorted + $d, 'Potentials'));
			case 'leadstatus':
				return array('type' => 'doughnut', 'data' => self::translated(self::countBy(
					"vtiger_leaddetails t JOIN vtiger_crmentity e ON e.crmid=t.leadid AND e.deleted=0 AND t.converted=0", 't.leadstatus', 7), 'Leads'));
			case 'tickets':
				return array('type' => 'bar', 'data' => self::translated(self::countBy(
					"vtiger_troubletickets t JOIN vtiger_crmentity e ON e.crmid=t.ticketid AND e.deleted=0", 't.status'), 'HelpDesk'));
			case 'won':
				// amount of won opportunities per Jalali month (by expected close date), last 12 months
				$months = self::lastMonths();
				$from = array_keys($months)[0] . '-01';
				$r = $adb->pquery("SELECT DATE_FORMAT(t.closingdate,'%Y-%m') m, SUM(IFNULL(t.amount,0)) s FROM vtiger_potential t
					JOIN vtiger_crmentity e ON e.crmid=t.potentialid AND e.deleted=0
					WHERE t.sales_stage='Closed Won' AND t.closingdate >= ?" . self::ownerSql() . " GROUP BY m", array($from));
				$vals = array_fill_keys(array_keys($months), 0);
				while ($row = $adb->fetch_array($r)) { if (isset($vals[$row['m']])) { $vals[$row['m']] = round((float) $row['s']); } }
				return array('type' => 'line', 'labels' => array_values($months), 'series' => array('مبلغ فروش موفق' => array_values($vals)));
			case 'completeness':
				if (!self::hasColumn('vtiger_contactscf', 'cf_ss_profile_completeness')) { return array('type' => 'bar', 'data' => array()); }
				$buckets = array('۰–۴۰٪' => 0, '۴۱–۶۰٪' => 0, '۶۱–۸۰٪' => 0, '۸۱–۹۹٪' => 0, '۱۰۰٪' => 0);
				$r = $adb->pquery("SELECT CAST(REPLACE(IFNULL(cf.cf_ss_profile_completeness,'0'),'%','') AS UNSIGNED) p, COUNT(*) n
					FROM vtiger_contactdetails cd JOIN vtiger_crmentity e ON e.crmid=cd.contactid AND e.deleted=0 $cf
					WHERE 1=1" . self::ownerSql() . " GROUP BY p", array());
				while ($row = $adb->fetch_array($r)) {
					$p = (int) $row['p'];
					$k = $p >= 100 ? '۱۰۰٪' : ($p > 80 ? '۸۱–۹۹٪' : ($p > 60 ? '۶۱–۸۰٪' : ($p > 40 ? '۴۱–۶۰٪' : '۰–۴۰٪')));
					$buckets[$k] += (int) $row['n'];
				}
				return array('type' => 'bar', 'data' => $buckets);
			case 'activities':
				$r = $adb->pquery("SELECT CASE WHEN a.activitytype='Task' THEN IFNULL(a.status,'') ELSE IFNULL(a.eventstatus,'') END s, COUNT(*) n
					FROM vtiger_activity a JOIN vtiger_crmentity e ON e.crmid=a.activityid AND e.deleted=0
					WHERE a.activitytype <> 'Emails'" . self::ownerSql() . " GROUP BY s ORDER BY n DESC", array());
				$names = array('Completed' => 'تکمیل‌شده', 'Not Started' => 'شروع نشده', 'In Progress' => 'در حال انجام', 'Deferred' => 'معوق',
					'Pending Input' => 'منتظر ورودی', 'Planned' => 'برنامه‌ریزی‌شده', 'Held' => 'برگزارشده', 'Not Held' => 'برگزارنشده', '' => 'نامشخص');
				$d = array();
				while ($row = $adb->fetch_array($r)) { $k = isset($names[$row['s']]) ? $names[$row['s']] : $row['s']; $d[$k] = (isset($d[$k]) ? $d[$k] : 0) + (int) $row['n']; }
				return array('type' => 'polarArea', 'data' => $d);
			case 'trend':
				// last 12 Jalali months: new contacts and activities
				$months = self::lastMonths();
				$from = array_keys($months)[0] . '-01';
				$series = array();
				foreach (array('Contacts' => 'مخاطبان جدید', 'Calendar' => 'فعالیت‌ها') as $setype => $label) {
					$r = $adb->pquery("SELECT DATE_FORMAT(e.createdtime,'%Y-%m') m, COUNT(*) n FROM vtiger_crmentity e
						WHERE e.deleted=0 AND e.setype=? AND e.createdtime >= ?" . self::ownerSql() . " GROUP BY m", array($setype, $from));
					$vals = array_fill_keys(array_keys($months), 0);
					while ($row = $adb->fetch_array($r)) { if (isset($vals[$row['m']])) { $vals[$row['m']] = (int) $row['n']; } }
					$series[$label] = array_values($vals);
				}
				return array('type' => 'line', 'labels' => array_values($months), 'series' => $series);
			case 'week':
				// open tasks / events in the next 14 days, by day
				$from = self::today();
				$to = date('Y-m-d', strtotime($from . ' +13 day'));
				$days = self::activityDays($from, $to);
				$labels = array(); $vals = array();
				for ($i = 0; $i < 14; $i++) {
					$g = date('Y-m-d', strtotime($from . " +$i day"));
					list($gy, $gm, $gd) = array_map('intval', explode('-', $g));
					list(, $jm, $jd) = self::g2j($gy, $gm, $gd);
					$labels[] = $jd . ' ' . self::$J_MONTHS[$jm - 1];
					$vals[] = isset($days[$g]) ? $days[$g] : 0;
				}
				return array('type' => 'barline', 'labels' => $labels, 'series' => array('کارهای باز' => $vals));
		}
		return array('type' => 'bar', 'data' => array());
	}

	static $J_MONTHS = array('فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند');

	/** Gregorian -> Jalali (jalaali algorithm) */
	static function g2j($gy, $gm, $gd) {
		$g_d_m = array(0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334);
		$gy2 = $gm > 2 ? $gy + 1 : $gy;
		$days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100) + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
		$jy = -1595 + (33 * intdiv($days, 12053));
		$days %= 12053;
		$jy += 4 * intdiv($days, 1461);
		$days %= 1461;
		if ($days > 365) { $jy += intdiv($days - 1, 365); $days = ($days - 1) % 365; }
		if ($days < 186) { $jm = 1 + intdiv($days, 31); $jd = 1 + ($days % 31); }
		else { $jm = 7 + intdiv($days - 186, 30); $jd = 1 + (($days - 186) % 30); }
		return array($jy, $jm, $jd);
	}

	/** label of the Jalali month in which the given Gregorian month mostly falls (its 15th day) */
	static function jalaliMonthLabel($gy, $gm) {
		list($jy, $jm) = self::g2j($gy, $gm, 15);
		return self::$J_MONTHS[$jm - 1] . ' ' . $jy;
	}
}
