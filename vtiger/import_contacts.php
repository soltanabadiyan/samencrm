<?php
/**
 * SamenCRM – import a simple contact sheet (made by vtiger/sheet_to_contacts.py) into Organizations + Contacts.
 * Run INSIDE the web container:
 *   docker compose exec -T web php /opt/ss/import_contacts.php /opt/ss/contacts_sheet.json
 * Organizations are created once per name and linked. Every contact gets the sheet's tag.
 * Duplicates are skipped: same mobile (last 10 digits) as an existing contact or an earlier row;
 * rows without a phone are matched on name + organisation (names alone are not a reliable key).
 * Safe to run again.
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

$in = json_decode(file_get_contents($argv[1] ?? '/opt/ss/contacts_sheet.json'), true);
if (!$in || !isset($in['contacts'])) exit("bad input\n");
$tag = mb_substr(trim($in['tag'] ?? ''), 0, 50);

function p10($v) { $d = preg_replace('/\D/', '', (string)$v); return strlen($d) >= 9 ? substr($d, -10) : ''; }

// existing contacts: phones, and name|organisation for rows without phone
$seenPhone = array(); $seenName = array();
$r = $adb->pquery("SELECT c.contactid, c.mobile, c.phone, c.firstname, c.lastname, IFNULL(a.accountname,'') acc
	FROM vtiger_contactdetails c JOIN vtiger_crmentity e ON e.crmid=c.contactid AND e.deleted=0
	LEFT JOIN vtiger_account a ON a.accountid=c.accountid", array());
while ($row = $adb->fetchByAssoc($r, -1, false)) {
	foreach (array('mobile', 'phone') as $c) if (($k = p10($row[$c])) !== '') $seenPhone[$k] = 1;
	$seenName[trim($row['firstname'] . ' ' . $row['lastname']) . '|' . $row['acc']] = 1;
}
// existing organisations
$accounts = array();
$r = $adb->pquery("SELECT a.accountid, a.accountname FROM vtiger_account a JOIN vtiger_crmentity e ON e.crmid=a.accountid AND e.deleted=0", array());
while ($row = $adb->fetchByAssoc($r, -1, false)) $accounts[$row['accountname']] = (int)$row['accountid'];

function account_id($name, $city) {
	global $accounts;
	if ($name === '') return 0;
	if (isset($accounts[$name])) return $accounts[$name];
	$m = Vtiger_Record_Model::getCleanInstance('Accounts');
	$m->set('accountname', $name);
	$m->set('bill_city', $city);
	$m->set('assigned_user_id', Users::getActiveAdminId());
	$m->save();
	return $accounts[$name] = (int)$m->getId();
}
function tag_id($name) {
	global $adb, $current_user;
	$r = $adb->pquery('SELECT id FROM vtiger_freetags WHERE tag=?', array($name));
	if ($adb->num_rows($r)) return $adb->query_result($r, 0, 'id');
	$id = $adb->getUniqueId('vtiger_freetags');
	$adb->pquery('INSERT INTO vtiger_freetags (id, tag, raw_tag, visibility, owner) VALUES (?,?,?,?,?)', array($id, $name, $name, 'public', $current_user->id));
	return $id;
}
$tagId = $tag !== '' ? tag_id($tag) : 0;

$made = 0; $dup = 0; $acc0 = count($accounts);
foreach ($in['contacts'] as $c) {
	$k = p10($c['mobile'] ?? '');
	$nk = trim(($c['firstname'] ?? '') . ' ' . $c['lastname']) . '|' . ($c['account'] ?? '');
	if (($k !== '' && isset($seenPhone[$k])) || ($k === '' && isset($seenName[$nk]))) { $dup++; continue; }
	$m = Vtiger_Record_Model::getCleanInstance('Contacts');
	foreach (array('firstname', 'lastname', 'mobile', 'title', 'email') as $f) $m->set($f, (string)($c[$f] ?? ''));
	$m->set('salutationtype', $c['salutation'] ?? '');
	$m->set('mailingcity', $c['city'] ?? '');
	$m->set('mailingcountry', ($c['city'] ?? '') !== '' ? 'ایران' : '');
	$m->set('leadsource', '');
	$m->set('account_id', account_id($c['account'] ?? '', $c['city'] ?? ''));
	$m->set('assigned_user_id', Users::getActiveAdminId());
	$m->save();
	$id = (int)$m->getId();
	if ($tagId) $adb->pquery('INSERT INTO vtiger_freetagged_objects (tag_id, tagger_id, object_id, tagged_on, module) VALUES (?,?,?,?,?)
		ON DUPLICATE KEY UPDATE tag_id=tag_id', array($tagId, $current_user->id, $id, date('Y-m-d H:i:s'), 'Contacts'));
	if ($k !== '') $seenPhone[$k] = 1; else $seenName[$nk] = 1;
	$made++;
}
// the default «All» contacts list shows Office Phone; these sheets carry mobiles – show Mobile there instead
$adb->pquery("UPDATE vtiger_cvcolumnlist cl JOIN vtiger_customview v ON v.cvid=cl.cvid AND v.entitytype='Contacts' AND v.viewname='All'
	SET cl.columnname='vtiger_contactdetails:mobile:mobile:Contacts_Mobile:V'
	WHERE cl.columnname='vtiger_contactdetails:phone:phone:Contacts_Office_Phone:V'", array());
echo "contacts created $made, duplicates skipped $dup, organisations created " . (count($accounts) - $acc0) . ($tag !== '' ? ", tag «{$tag}»" : '') . "\n";
