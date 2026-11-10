<?php
/* Install / update the SamenAcademy package (run inside the web container from /var/www/html):
 *   php /opt/ss/academy/install_academy.php /opt/ss/academy/SamenAcademy.zip */
chdir('/var/www/html');
require_once 'vendor/autoload.php';
require_once 'config.php';
require_once 'include/utils/utils.php';
require_once 'vtlib/Vtiger/Package.php';
require_once 'vtlib/Vtiger/Module.php';
require_once 'includes/main/WebUI.php';
global $adb;
$zip = $argv[1] ?? '/opt/ss/academy/SamenAcademy.zip';
@mkdir('test/vtlib', 0777, true);
$staged = 'test/vtlib/' . basename($zip);
copy($zip, $staged);
$pkg = new Vtiger_Package();
$existing = Vtiger_Module::getInstance('SamenAcademy');
if ($existing) { $pkg->update($existing, $staged, true); echo "SamenAcademy: updated\n"; }
else { $pkg->import($staged, true); echo "SamenAcademy: installed\n"; }
require_once 'modules/SamenAcademy/SamenAcademy.php';
$h = new SamenAcademy();
$h->vtlib_handler('SamenAcademy', $existing ? 'module.postupdate' : 'module.postinstall');
$r = $adb->pquery('SELECT appname FROM vtiger_app2tab WHERE tabid=?', array(getTabid('SamenAcademy')));
$apps = array(); while ($row = $adb->fetch_array($r)) { $apps[] = $row['appname']; }
echo 'menus: ' . implode(', ', $apps) . "\n";
$r = $adb->pquery('SELECT linktype, linkurl FROM vtiger_links WHERE tabid=?', array(getTabid('SamenAcademy')));
while ($row = $adb->fetch_array($r)) { echo "  link {$row['linktype']} {$row['linkurl']}\n"; }
echo "ACADEMY DONE\n";
