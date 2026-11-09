<?php
/**
 * SamenStudent – tag every imported contact with its Level and Major (public tags).
 * Run INSIDE the web container after import_to_vtiger.py:
 *   docker compose exec -T web php /opt/ss/tag_contacts.php /opt/ss/dataset.json /opt/ss/import_state.json
 * Tags: "مقطع: کارشناسی ارشد", "رشته: <major>" (Vtiger tag max length is 50 chars). Idempotent.
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

$ds = json_decode(file_get_contents($argv[1] ?? '/opt/ss/dataset.json'), true);
$state = json_decode(file_get_contents($argv[2] ?? '/opt/ss/import_state.json'), true);
$map = $state['contacts'] ?? array();

$cache = array();
function tag_id($name) {
    global $cache, $adb, $current_user;
    $name = mb_substr(trim($name), 0, 50);
    if (isset($cache[$name])) return $cache[$name];
    $r = $adb->pquery('SELECT id FROM vtiger_freetags WHERE tag=?', array($name));
    if ($adb->num_rows($r)) {
        $id = $adb->query_result($r, 0, 'id');
    } else {
        $id = $adb->getUniqueId('vtiger_freetags');
        $adb->pquery('INSERT INTO vtiger_freetags (id, tag, raw_tag, visibility, owner) VALUES (?,?,?,?,?)',
            array($id, $name, $name, 'public', $current_user->id));
    }
    return $cache[$name] = $id;
}

$LEVEL_FA = array('PhD' => 'دکتری', 'MSc' => 'کارشناسی ارشد', 'BSc' => 'کارشناسی', 'Associate' => 'کاردانی', 'Other' => 'سایر', '' => 'نامشخص');
$now = date('Y-m-d H:i:s'); $n = 0;
foreach ($ds['contacts'] as $c) {
    $wsid = $map[(string)$c['_id']] ?? null;
    if (!$wsid) continue;
    $crmid = (int)explode('x', $wsid)[1];
    $tags = array();
    $lvl = $c['fields']['level'] ?? '';
    $tags[] = 'مقطع: ' . ($LEVEL_FA[$lvl] ?? $lvl);
    if (!empty($c['fields']['major'])) $tags[] = 'رشته: ' . $c['fields']['major'];
    foreach ($tags as $t) {
        $adb->pquery('INSERT INTO vtiger_freetagged_objects (tag_id, tagger_id, object_id, tagged_on, module)
                      VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE tag_id=tag_id',
            array(tag_id($t), $current_user->id, $crmid, $now, 'Contacts'));
        $n++;
    }
}
echo "tags: " . count($cache) . " distinct, $n links\n";
