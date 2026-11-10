<?php
/* Install / update the SamenWidgets package (run inside the web container from /var/www/html):
 *   php /opt/ss/widgets/install_widgets.php /opt/ss/widgets/SamenWidgets.zip */
chdir('/var/www/html');
require_once 'vendor/autoload.php';
require_once 'config.php';
require_once 'include/utils/utils.php';
require_once 'vtlib/Vtiger/Package.php';
require_once 'vtlib/Vtiger/Module.php';
require_once 'includes/main/WebUI.php';
global $adb;
$zip = $argv[1] ?? '/opt/ss/widgets/SamenWidgets.zip';
@mkdir('test/vtlib', 0777, true);
$staged = 'test/vtlib/' . basename($zip);
copy($zip, $staged);
$pkg = new Vtiger_Package();
$existing = Vtiger_Module::getInstance('SamenWidgets');
if ($existing) { $pkg->update($existing, $staged, true); echo "SamenWidgets: updated\n"; }
else { $pkg->import($staged, true); echo "SamenWidgets: installed\n"; }
require_once 'modules/SamenWidgets/SamenWidgets.php';
$h = new SamenWidgets();
$h->vtlib_handler('SamenWidgets', $existing ? 'module.postupdate' : 'module.postinstall');
$r = $adb->pquery('SELECT appname FROM vtiger_app2tab WHERE tabid=?', array(getTabid('SamenWidgets')));
$apps = array(); while ($row = $adb->fetch_array($r)) { $apps[] = $row['appname']; }
echo 'menus: ' . implode(', ', $apps) . "\n";
$r = $adb->pquery('SELECT linktype, linkurl FROM vtiger_links WHERE tabid=?', array(getTabid('SamenWidgets')));
while ($row = $adb->fetch_array($r)) { echo "  link {$row['linktype']} {$row['linkurl']}\n"; }
$w = $adb->pquery("SELECT COUNT(*) c FROM vtiger_module_dashboard_widgets w JOIN vtiger_links l ON l.linkid=w.linkid WHERE l.linkurl LIKE ?", array('index.php?module=SamenWidgets&%'));
echo 'widgets on dashboards: ' . $adb->query_result($w, 0, 'c') . "\n";
echo "WIDGETS DONE\n";
