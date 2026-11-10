<?php
/**
 * SamenCRM – install / update the TelegramBot vtlib package (run INSIDE the web container):
 *   php /opt/ss/telegram/install_telegram.php /opt/ss/telegram/TelegramBot.zip
 * Idempotent: imports the package the first time, updates it afterwards, then (re)creates tables, links,
 * cron tasks and the settings entry.
 */
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING & ~E_STRICT);
ini_set('display_errors', '1');
$zip = isset($argv[1]) ? $argv[1] : '/opt/ss/telegram/TelegramBot.zip';
if (!is_file($zip)) { fwrite(STDERR, "Package not found: $zip\n"); exit(2); }

chdir(getenv('VTIGER_ROOT') ?: '/var/www/html');
require_once 'vendor/autoload.php';
require_once 'config.php';
require_once 'include/utils/utils.php';
vimport('includes.runtime.EntryPoint');
require_once 'vtlib/Vtiger/Package.php';
require_once 'vtlib/Vtiger/Module.php';
require_once 'modules/Users/Users.php';

global $adb, $current_user;
$adb = PearDatabase::getInstance();
$current_user = Users::getActiveAdminUser();

$package = new Vtiger_Package();
$module = Vtiger_Module::getInstance('TelegramBot');
if ($module) {
	echo "Updating TelegramBot {$module->version} ...\n";
	$package->update($module, $zip);
} else {
	echo "Installing TelegramBot ...\n";
	$package->import($zip, true);
}
$module = Vtiger_Module::getInstance('TelegramBot');
if (!$module) { fwrite(STDERR, "Install failed\n"); exit(1); }
require_once 'modules/TelegramBot/TelegramBot.php';
TelegramBot::install();          // idempotent; also covers updates where vtlib events are not fired
$adb->pquery('UPDATE vtiger_tab SET presence=0 WHERE name=?', array('TelegramBot'));
create_tab_data_file();
create_parenttab_data_file();
Vtiger_Cache::delete('module', 'TelegramBot');

$r = $adb->pquery("SELECT name, frequency, status FROM vtiger_cron_task WHERE module='TelegramBot'", array());
while ($row = $adb->fetchByAssoc($r)) { echo "cron: {$row['name']} every {$row['frequency']}s status={$row['status']}\n"; }
$r = $adb->pquery("SELECT linktype, linklabel FROM vtiger_links WHERE linkurl LIKE '%TelegramBot%'", array());
while ($row = $adb->fetchByAssoc($r)) { echo "link: {$row['linktype']} {$row['linklabel']}\n"; }
echo "TelegramBot " . $module->version . " installed (tabid {$module->id}).\n";
