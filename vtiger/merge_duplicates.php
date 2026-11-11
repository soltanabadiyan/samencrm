<?php
/**
 * SamenCRM – merge duplicate contacts.
 * Run INSIDE the web container:
 *   docker compose exec -T web php /opt/ss/merge_duplicates.php            # dry run: list pairs only
 *   docker compose exec -T web php /opt/ss/merge_duplicates.php --apply    # merge
 *
 * Keys: passport number, then phone number (mobile / office / home / other, compared on the last 10 digits).
 * First and last names are NOT used: they are unreliable in the source data.
 * Two contacts that share a phone but carry different passport numbers are different people
 * (agents, offices and families share numbers) and are never merged.
 *
 * Per group the record with a passport (then the oldest) is kept. Its empty fields are filled from the
 * duplicates, list/text-area fields are combined, related records (activities, emails, comments, documents,
 * tickets …) and tags are moved, and the duplicate is deleted the normal Vtiger way (Recycle Bin).
 * Field values are written with SQL so no workflow (e-mail, Telegram) fires for the merge.
 */
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
chdir(getenv('VTIGER_ROOT') ?: '/var/www/html');
require_once 'vendor/autoload.php';
require_once 'config.php';
require_once 'include/utils/utils.php';
vimport('includes.runtime.EntryPoint');
require_once 'modules/Users/Users.php';

global $adb, $current_user;
$adb = PearDatabase::getInstance();
$current_user = Users::getActiveAdminUser();
vglobal('current_user', $current_user);
$apply = in_array('--apply', $argv, true);

function passport_key($v) {
	$v = strtoupper(preg_replace('/[\s\-_\/.]+/u', '', (string)$v));
	return (strlen($v) >= 5 && preg_match('/\d/', $v)) ? $v : '';   // «منقضی», «/////» are not passports
}
function phone_key($v) {
	$v = strtr((string)$v, array('۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
		'٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9'));
	$d = preg_replace('/\D/', '', $v);
	return strlen($d) >= 9 ? substr($d, -10) : '';
}

// ---- load contacts
$res = $adb->pquery("SELECT c.contactid, cf.cf_ss_passport, c.mobile, c.phone, s.homephone, s.otherphone
	FROM vtiger_contactdetails c JOIN vtiger_crmentity e ON e.crmid=c.contactid AND e.deleted=0
	LEFT JOIN vtiger_contactscf cf ON cf.contactid=c.contactid
	LEFT JOIN vtiger_contactsubdetails s ON s.contactsubscriptionid=c.contactid", array());
$pass = array(); $keys = array();
while ($r = $adb->fetchByAssoc($res, -1, false)) {
	$id = (int)$r['contactid'];
	$pass[$id] = passport_key($r['cf_ss_passport']);
	$k = array();
	if ($pass[$id] !== '') $k[] = 'P:' . $pass[$id];
	foreach (array('mobile', 'phone', 'homephone', 'otherphone') as $c) { $p = phone_key($r[$c]); if ($p !== '') $k[] = 'T:' . $p; }
	$keys[$id] = array_unique($k);
}

// ---- group: union-find over shared keys, but never join two different passports
$parent = array(); $gpass = array();   // root => passport of the group ('' if none yet)
function root($x) { global $parent; while ($parent[$x] !== $x) { $parent[$x] = $parent[$parent[$x]]; $x = $parent[$x]; } return $x; }
foreach ($pass as $id => $p) { $parent[$id] = $id; $gpass[$id] = $p; }
$byKey = array();
foreach ($keys as $id => $ks) foreach ($ks as $k) $byKey[$k][] = $id;
$skipped = 0;
foreach ($byKey as $k => $ids) {
	if (count($ids) < 2) continue;
	// a phone shared by contacts with two or more different passports is a shared number: no merging on it
	if ($k[0] === 'T') {
		$ps = array_unique(array_filter(array_map(function ($i) use ($pass) { return $pass[$i]; }, $ids)));
		if (count($ps) > 1) { $skipped++; continue; }
	}
	foreach (array_slice($ids, 1) as $id) {
		$a = root($ids[0]); $b = root($id);
		if ($a === $b) continue;
		if ($gpass[$a] !== '' && $gpass[$b] !== '' && $gpass[$a] !== $gpass[$b]) continue;
		$parent[$b] = $a; if ($gpass[$a] === '') $gpass[$a] = $gpass[$b];
	}
}
$groups = array();
foreach ($parent as $id => $_) $groups[root($id)][] = $id;
$groups = array_values(array_filter($groups, function ($g) { return count($g) > 1; }));
echo 'contacts ' . count($pass) . ', shared phone numbers skipped ' . $skipped . ', duplicate groups ' . count($groups) . "\n";

// ---- fields that can be merged (contact tables only, not crmentity / owner / numbering)
$tables = array('vtiger_contactdetails' => 'contactid', 'vtiger_contactsubdetails' => 'contactsubscriptionid',
	'vtiger_contactaddress' => 'contactaddressid', 'vtiger_contactscf' => 'contactid', 'vtiger_customerdetails' => 'customerid',
	'vtiger_crmentity' => 'crmid');
$fields = array();
$fr = $adb->pquery("SELECT columnname, tablename, uitype FROM vtiger_field WHERE tabid=? AND presence IN (0,2)", array(getTabid('Contacts')));
while ($f = $adb->fetchByAssoc($fr, -1, false)) {
	if (!isset($tables[$f['tablename']]) || in_array((int)$f['uitype'], array(4, 52, 53, 70, 69), true)) continue;
	if ($f['tablename'] === 'vtiger_crmentity' && $f['columnname'] !== 'description') continue;
	$fields[] = $f;
}
function row_of($id) {
	global $adb, $tables; $out = array();
	foreach ($tables as $t => $col) {
		$r = $adb->pquery("SELECT * FROM $t WHERE $col=?", array($id));
		$out[$t] = $adb->num_rows($r) ? $adb->fetchByAssoc($r, -1, false) : null;
	}
	return $out;
}
function combine($a, $b, $uitype, $col) {
	if ($col === 'cf_ss_source_groups') {
		$parts = array_unique(array_filter(array_map('trim', array_merge(explode(';', $a), explode(';', $b)))));
		return implode('; ', $parts);
	}
	return (strpos($a, $b) !== false) ? $a : rtrim($a) . "\n" . $b;
}

$done = 0;
foreach ($groups as $g) {
	sort($g);
	usort($g, function ($x, $y) use ($pass) { return (($pass[$x] === '') <=> ($pass[$y] === '')) ?: ($x <=> $y); });
	$keep = $g[0]; $drop = array_slice($g, 1);
	echo "keep $keep  merge " . implode(',', $drop) . "\n";
	if (!$apply) continue;

	$k = row_of($keep);
	foreach ($drop as $d) {
		$o = row_of($d);
		foreach ($fields as $f) {
			$t = $f['tablename']; $c = $f['columnname'];
			if ($k[$t] === null || $o[$t] === null || !array_key_exists($c, $o[$t])) continue;
			$kv = trim((string)$k[$t][$c]); $ov = trim((string)$o[$t][$c]);
			if ($ov === '' || $ov === $kv || $ov === '0000-00-00') continue;
			if ($kv === '' || $kv === '0000-00-00') $nv = $ov;
			elseif (in_array((int)$f['uitype'], array(19, 21), true)) $nv = combine($kv, $ov, (int)$f['uitype'], $c);
			else continue;   // both filled and different: the kept record wins
			$adb->pquery("UPDATE $t SET $c=? WHERE {$tables[$t]}=?", array($nv, $keep));
			$k[$t][$c] = $nv;
		}
		// tags
		$adb->pquery("UPDATE IGNORE vtiger_freetagged_objects SET object_id=? WHERE object_id=?", array($keep, $d));
		$adb->pquery("DELETE FROM vtiger_freetagged_objects WHERE object_id=?", array($d));
		// tasks / events / notes linked as contact (Vtiger's merge below does not move these)
		$adb->pquery("UPDATE IGNORE vtiger_cntactivityrel SET contactid=? WHERE contactid=?", array($keep, $d));
		$adb->pquery("DELETE FROM vtiger_cntactivityrel WHERE contactid=?", array($d));
		// related records (activities, emails, comments, documents, tickets, potentials …) – Vtiger's own merge logic
		$keepModel = Vtiger_Record_Model::getInstanceById($keep, 'Contacts');
		$keepModel->transferRelationInfoOfRecords(array($d));
		Vtiger_Record_Model::getInstanceById($d, 'Contacts')->delete();
		$done++;
	}
	$adb->pquery("UPDATE vtiger_crmentity SET modifiedtime=? WHERE crmid=?", array(date('Y-m-d H:i:s'), $keep));
}
echo $apply ? "merged and deleted $done duplicates\n" : "dry run – add --apply to merge\n";
