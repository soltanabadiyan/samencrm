<?php
/**
 * SamenStudent – install the Persian language pack + PersianCalendar extension the vtlib way
 * (same code path as Settings > Module Management > Import Module from Zip), then make Persian the default.
 * Run INSIDE the web container:
 *   php /opt/ss/install_persian.php /opt/ss/Persian_fa_ir.zip /opt/ss/PersianCalendar.zip [--all-users]
 *
 *  - config.inc.php: $default_language = 'fa_ir' (login page + new users)
 *  - --all-users: every active user -> language fa_ir, week starts Saturday, timezone Asia/Tehran,
 *    date format yyyy-mm-dd (the Jalali layer reads this format)
 * Idempotent: re-running updates both packages in place.
 */
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING & ~E_STRICT);
chdir(getenv('VTIGER_ROOT') ?: '/var/www/html');
require_once 'vendor/autoload.php';
require_once 'config.php';
require_once 'include/utils/utils.php';
vimport('includes.runtime.EntryPoint');
require_once 'vtlib/Vtiger/Package.php';
require_once 'vtlib/Vtiger/Module.php';
require_once 'modules/Users/Users.php';
require_once 'modules/Users/CreateUserPrivilegeFile.php';

global $adb, $current_user;
$adb = PearDatabase::getInstance();
$current_user = Users::getActiveAdminUser();
vglobal('current_user', $current_user);

$langZip = $argv[1] ?? '/opt/ss/Persian_fa_ir.zip';
$modZip = $argv[2] ?? '/opt/ss/PersianCalendar.zip';
$allUsers = in_array('--all-users', $argv);

// vtlib extracts relative to cwd and works on a copy in test/vtlib
@mkdir('test/vtlib', 0777, true);
function staged($zip) { $t = 'test/vtlib/' . basename($zip); copy($zip, $t); return $t; }

/* 1. language pack */
require_once 'vtlib/Vtiger/Language.php';
$lang = new Vtiger_Language();             // Module Manager uses this class for <type>language</type>
$lang->import(staged($langZip), true);
$r = $adb->pquery('SELECT prefix, label, active FROM vtiger_language WHERE prefix=?', array('fa_ir'));
echo 'language: ' . ($adb->num_rows($r) ? 'fa_ir registered (' . $adb->query_result($r, 0, 'label') . ')' : 'NOT registered') . "\n";
$adb->pquery('UPDATE vtiger_language SET active=1 WHERE prefix=?', array('fa_ir'));

/* 2. PersianCalendar extension */
$zip = staged($modZip);
$pkg = new Vtiger_Package();
$existing = Vtiger_Module::getInstance('PersianCalendar');
if ($existing) {
	$pkg->update($existing, $zip, true);
	echo "PersianCalendar: updated\n";
} else {
	$pkg->import($zip, true);
	echo "PersianCalendar: installed\n";
}
$mod = Vtiger_Module::getInstance('PersianCalendar');
if ($mod) {
	require_once 'modules/PersianCalendar/PersianCalendar.php';
	PersianCalendar::registerLinks();      // also covers the update path
	$r = $adb->pquery('SELECT linktype, linkurl FROM vtiger_links WHERE tabid=?', array($mod->id));
	while ($row = $adb->fetch_array($r)) { echo "  link {$row['linktype']} {$row['linkurl']}\n"; }
}

/* 3. defaults */
$cfg = file_get_contents('config.inc.php');
$new = preg_replace("/^\\\$default_language\s*=\s*'[^']*';/m", "\$default_language = 'fa_ir';", $cfg);
if ($new !== $cfg) { file_put_contents('config.inc.php', $new); echo "config.inc.php: default_language = fa_ir\n"; }

if ($allUsers) {
	$adb->pquery("UPDATE vtiger_users SET language='fa_ir', dayoftheweek='Saturday', time_zone='Asia/Tehran', date_format='yyyy-mm-dd' WHERE status='Active'", array());
	$r = $adb->pquery("SELECT id, user_name FROM vtiger_users WHERE status='Active'", array());
	while ($row = $adb->fetch_array($r)) {
		createUserPrivilegesfile($row['id']);
		echo "user {$row['user_name']}: fa_ir, Saturday, Asia/Tehran, yyyy-mm-dd\n";
	}
}
/* 4. Vtiger's built-in sample names (only while they still have their default English names) */
$renames = array(
	array('vtiger_groups', 'groupname', array('Marketing Group' => 'گروه بازاریابی', 'Support Group' => 'گروه پشتیبانی', 'Team Selling' => 'تیم فروش')),
	array('vtiger_role', 'rolename', array('Organization' => 'سازمان', 'CEO' => 'مدیرعامل', 'Vice President' => 'معاون', 'Sales Manager' => 'مدیر فروش', 'Sales Person' => 'کارشناس فروش')),
	array('vtiger_profile', 'profilename', array('Administrator' => 'مدیر سیستم', 'Sales Profile' => 'پروفایل فروش', 'Support Profile' => 'پروفایل پشتیبانی', 'Guest Profile' => 'پروفایل مهمان')),
);
foreach ($renames as $rn) {
	list($table, $col, $map) = $rn;
	foreach ($map as $en => $fa) {
		$adb->pquery("UPDATE $table SET $col=? WHERE $col=?", array($fa, $en));
		if (true) { echo "renamed $table: $en -> $fa\n"; }
	}
}
echo "PERSIAN DONE\n";
