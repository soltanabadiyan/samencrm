<?php
/**
 * SamenStudent – install the Persian language pack + PersianCalendar extension the vtlib way
 * (same code path as Settings > Module Management > Import Module from Zip), then make Persian the default.
 * Run INSIDE the web container:
 *   php /opt/ss/install_persian.php /opt/ss/Persian_fa_ir.zip /opt/ss/PersianCalendar.zip /opt/ss/SamenTheme.zip [--all-users]
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

/* 2. extensions: PersianCalendar (Jalali) and SamenTheme (custom skin, login page, logo) */
function install_extension($name, $zipPath) {
	global $adb;
	if (!$zipPath || !file_exists($zipPath)) { echo "$name: zip not given, skipped\n"; return; }
	$zip = staged($zipPath);
	$pkg = new Vtiger_Package();
	$existing = Vtiger_Module::getInstance($name);
	if ($existing) { $pkg->update($existing, $zip, true); echo "$name: updated\n"; }
	else { $pkg->import($zip, true); echo "$name: installed\n"; }
	$mod = Vtiger_Module::getInstance($name);
	if (!$mod) { echo "$name: NOT installed\n"; return; }
	require_once "modules/$name/$name.php";
	// vtlib only fires module.postupdate for some package types – run the handler explicitly (idempotent)
	$h = new $name();
	$h->vtlib_handler($name, $existing ? 'module.postupdate' : 'module.postinstall');
	$r = $adb->pquery('SELECT linktype, linkurl FROM vtiger_links WHERE tabid=?', array($mod->id));
	while ($row = $adb->fetch_array($r)) { echo "  link {$row['linktype']} {$row['linkurl']}\n"; }
}
install_extension('PersianCalendar', $modZip);
install_extension('SamenTheme', $argv[3] ?? '/opt/ss/SamenTheme.zip');

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
		$r = $adb->pquery("SELECT 1 FROM $table WHERE $col=?", array($en));
		if ($adb->num_rows($r)) {
			$adb->pquery("UPDATE $table SET $col=? WHERE $col=?", array($fa, $en));
			echo "renamed $table: $en -> $fa\n";
		}
	}
}
/* 5. admin display name created by the scripted installer (earlier runs used other names) */
$adb->pquery("UPDATE vtiger_users SET first_name=?, last_name=? WHERE user_name='admin' AND ((first_name='SamenStudent' AND last_name='Admin') OR (first_name='مدیر' AND last_name='ثامن استودنت'))", array('مدیر', 'سامانه'));

/* 6. Vtiger's sample reports and report folders (not referenced by name in code; sample filters and the
      "My Dashboard" tab ARE, so those are only translated on screen by SamenTheme/theme.js) */
$reports = array(
	'Contacts by Accounts' => 'مخاطبین بر اساس سازمان', 'Contacts without Accounts' => 'مخاطبین بدون سازمان',
	'Contacts by Potentials' => 'مخاطبین بر اساس فرصت', 'Lead by Source' => 'سرنخ‌ها بر اساس منبع',
	'Lead Status Report' => 'گزارش وضعیت سرنخ', 'Potential Pipeline' => 'خط لوله فرصت‌ها', 'Closed Potentials' => 'فرصت‌های بسته‌شده',
	'Last Month Activities' => 'فعالیت‌های ماه گذشته', 'This Month Activities' => 'فعالیت‌های این ماه',
	'Tickets by Products' => 'تیکت‌ها بر اساس محصول', 'Tickets by Priority' => 'تیکت‌ها بر اساس اولویت', 'Open Tickets' => 'تیکت‌های باز',
	'Product Details' => 'جزئیات محصولات', 'Products by Contacts' => 'محصولات بر اساس مخاطب', 'Open Quotes' => 'پیش‌فاکتورهای باز',
	'Quotes Detailed Report' => 'گزارش تفصیلی پیش‌فاکتورها', 'PurchaseOrder by Contacts' => 'سفارش خرید بر اساس مخاطب',
	'PurchaseOrder Detailed Report' => 'گزارش تفصیلی سفارش خرید', 'Invoice Detailed Report' => 'گزارش تفصیلی فاکتور',
	'SalesOrder Detailed Report' => 'گزارش تفصیلی سفارش فروش', 'Campaign Expectations and Actuals' => 'انتظارات و واقعیات کمپین',
	'Contacts Email Report' => 'گزارش ایمیل مخاطبین', 'Accounts Email Report' => 'گزارش ایمیل سازمان‌ها',
	'Leads Email Report' => 'گزارش ایمیل سرنخ‌ها', 'Vendors Email Report' => 'گزارش ایمیل تأمین‌کنندگان',
);
// Reports double-escapes the zero-width non-joiner (shows "&zwnj;"), so report/folder names use a plain space
$zwnj = function ($s) { return str_replace("\u{200C}", ' ', $s); };
foreach ($reports as $en => $fa) {
	$adb->pquery('UPDATE vtiger_report SET reportname=? WHERE reportname IN (?, ?)', array($zwnj($fa), $en, $fa));
}
$folders = array(
	'Organization and Contact Reports' => 'گزارش‌های سازمان و مخاطب', 'Lead Reports' => 'گزارش‌های سرنخ',
	'Opportunity Reports' => 'گزارش‌های فرصت', 'Activity Reports' => 'گزارش‌های فعالیت', 'Tickets Reports' => 'گزارش‌های تیکت',
	'Product Reports' => 'گزارش‌های محصول', 'Quote Reports' => 'گزارش‌های پیش‌فاکتور', 'Purchase Order Reports' => 'گزارش‌های سفارش خرید',
	'Invoice Reports' => 'گزارش‌های فاکتور', 'Sales Order Reports' => 'گزارش‌های سفارش فروش', 'Campaign Reports' => 'گزارش‌های کمپین',
	'Email Reports' => 'گزارش‌های ایمیل',
);
foreach ($folders as $en => $fa) {
	$adb->pquery('UPDATE vtiger_reportfolder SET foldername=?, description=? WHERE foldername IN (?, ?)', array($zwnj($fa), $zwnj($fa), $en, $fa));
}
echo "sample reports/folders: Persian names\n";
createUserPrivilegesfile(1);
echo "PERSIAN DONE\n";
