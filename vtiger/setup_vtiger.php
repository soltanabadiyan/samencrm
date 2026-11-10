<?php
/**
 * SamenStudent – Vtiger schema setup (run INSIDE the web container, AFTER the web installer finished):
 *
 *   docker compose exec -T web php /opt/ss/setup_vtiger.php /opt/ss/schema.json
 *
 * Creates (idempotently):
 *   - 3 custom blocks + ~60 custom fields on Contacts (picklists for Level/University/Major/...)
 *   - user Groups per level  (contacts are assigned to them by the importer)
 *   - saved list filters (per level / university / major / source list)
 *   - adds key columns to the default "All" contacts list view
 * Prints a JSON line  SS_GROUPS={...}  with group ids for the importer.
 */
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
ini_set('display_errors', '1');

$root = getenv('VTIGER_ROOT') ?: '/var/www/html';
chdir($root);
require_once 'vendor/autoload.php';   // Vtiger 8: composer libs (Monolog etc.)
require_once 'config.php';
require_once 'include/utils/utils.php';
vimport('includes.runtime.EntryPoint');
require_once 'vtlib/Vtiger/Module.php';
require_once 'vtlib/Vtiger/Filter.php';
require_once 'modules/Users/Users.php';

global $adb, $current_user;
$adb = PearDatabase::getInstance();
$current_user = Users::getActiveAdminUser();
if (!$current_user) { fwrite(STDERR, "Cannot load admin user - did the web installer finish?\n"); exit(2); }

$schemaFile = $argv[1] ?? '/opt/ss/schema.json';
$schema = json_decode(file_get_contents($schemaFile), true);
if (!$schema) { fwrite(STDERR, "Bad schema file $schemaFile\n"); exit(2); }

$module = Vtiger_Module::getInstance('Contacts');
if (!$module) { fwrite(STDERR, "Contacts module not found\n"); exit(2); }

/* ------------------------------------------------------------------ fields */
$created = 0; $existing = 0; $errors = array();
foreach ($schema['blocks'] as $bdef) {
    $block = Vtiger_Block::getInstance($bdef['label'], $module);
    if (!$block) {
        $block = new Vtiger_Block();
        $block->label = $bdef['label'];
        $module->addBlock($block);
        echo "block created: {$bdef['label']}\n";
    }
    foreach ($bdef['fields'] as $f) {
        try {
            if (Vtiger_Field::getInstance($f['name'], $module)) { $existing++; continue; }
            $field = new Vtiger_Field();
            $field->name = $f['name'];
            $field->column = $f['name'];
            $field->label = $f['label'];
            $field->table = 'vtiger_contactscf';
            $field->typeofdata = 'V~O';
            if ($f['kind'] === 'picklist') {
                $field->uitype = 16;               // non-role-based picklist
                $field->columntype = 'VARCHAR(255)';
            } elseif ($f['kind'] === 'url') {
                $field->uitype = 17;               // URL (clickable)
                $field->columntype = 'VARCHAR(255)';
            } elseif ($f['kind'] === 'long') {
                $field->uitype = 21;               // textarea
                $field->columntype = 'TEXT';
            } else {
                $field->uitype = 1;
                $field->columntype = 'VARCHAR(255)';
            }
            $block->addField($field);
            if ($f['kind'] === 'picklist') {
                $field->setPicklistValues($f['values']);
            }
            $created++;
        } catch (Exception $e) {
            $errors[] = $f['name'] . ': ' . $e->getMessage();
        }
    }
}
echo "fields created: $created, already existed: $existing, errors: " . count($errors) . "\n";
foreach ($errors as $e) { echo "  ERROR $e\n"; }

/* picklist values: top up if the field existed but new values appeared */
foreach ($schema['blocks'] as $bdef) {
    foreach ($bdef['fields'] as $f) {
        if ($f['kind'] !== 'picklist') continue;
        $field = Vtiger_Field::getInstance($f['name'], $module);
        if (!$field) continue;
        $tbl = 'vtiger_' . $f['name'];
        $have = array();
        $r = $adb->pquery("SELECT {$f['name']} AS v FROM $tbl", array());
        while ($row = $adb->fetch_array($r)) { $have[html_entity_decode($row['v'], ENT_QUOTES, 'UTF-8')] = 1; }  // fetch_array HTML-encodes
        $missing = array();
        foreach ($f['values'] as $v) { if (!isset($have[$v])) $missing[] = $v; }
        if ($missing && $have) { try { $field->setPicklistValues($missing); echo "picklist {$f['name']}: +" . count($missing) . "\n"; } catch (Exception $e) {} }
    }
}

/* ------------------------------------------------------------------ groups */
$groupIds = array();
foreach ($schema['groups'] as $g) {
    $r = $adb->pquery('SELECT groupid FROM vtiger_groups WHERE groupname=?', array($g['name']));
    if ($adb->num_rows($r) > 0) {
        $gid = $adb->query_result($r, 0, 'groupid');
    } else {
        $gid = $adb->getUniqueID('vtiger_users');
        $adb->pquery('INSERT INTO vtiger_groups (groupid, groupname, description) VALUES (?,?,?)',
            array($gid, $g['name'], $g['desc']));
        $adb->pquery('INSERT INTO vtiger_users2group (groupid, userid) VALUES (?,?)', array($gid, $current_user->id));
        echo "group created: {$g['name']} ($gid)\n";
    }
    $groupIds[$g['level']] = (int)$gid;
}
try {
    require_once 'modules/Users/CreateUserPrivilegeFile.php';
    createUserPrivilegesfile($current_user->id);
    createUserSharingPrivilegesfile($current_user->id);
} catch (Exception $e) { echo "privilege file refresh failed: " . $e->getMessage() . "\n"; }
echo 'SS_GROUPS=' . json_encode($groupIds, JSON_UNESCAPED_UNICODE) . "\n";

/* ------------------------------------------------------------------ filters */
$fcreated = 0;
foreach ($schema['filters'] as $fd) {
    try {
        $exists = $adb->pquery("SELECT cvid FROM vtiger_customview WHERE viewname=? AND entitytype='Contacts'", array($fd['name']));
        if ($adb->num_rows($exists) > 0) continue;
        $field = Vtiger_Field::getInstance($fd['field'], $module);
        if (!$field) continue;
        $filter = new Vtiger_Filter();
        $filter->name = $fd['name'];
        $filter->isdefault = false;
        $module->addFilter($filter);
        $i = 0;
        foreach (array('lastname', 'firstname', 'mobile', 'cf_ss_passport', 'cf_ss_level', 'cf_ss_university', 'cf_ss_major') as $col) {
            $cf = Vtiger_Field::getInstance($col, $module);
            if ($cf) { $filter->addField($cf, $i++); }
        }
        $filter->addRule($field, $fd['comparator'], $fd['value']);
        $fcreated++;
    } catch (Exception $e) {
        echo "  filter {$fd['name']} failed: " . $e->getMessage() . "\n";
    }
}
echo "filters created: $fcreated\n";

/* ------------------------------------------------------------------ list view columns on "All" */
try {
    $all = Vtiger_Filter::getInstance('All', $module);
    if ($all) {
        $r = $adb->pquery('SELECT MAX(columnindex) AS m FROM vtiger_cvcolumnlist WHERE cvid=?', array($all->id));
        $idx = (int)$adb->query_result($r, 0, 'm') + 1;
        foreach ($schema['list_columns'] as $col) {
            $cf = Vtiger_Field::getInstance($col, $module);
            if (!$cf) continue;
            $colname = $cf->table . ':' . $cf->column . ':' . $cf->name . ':Contacts_' . str_replace(' ', '_', $cf->label) . ':V';
            $dup = $adb->pquery('SELECT 1 FROM vtiger_cvcolumnlist WHERE cvid=? AND columnname=?', array($all->id, $colname));
            if ($adb->num_rows($dup) == 0) {
                $adb->pquery('INSERT INTO vtiger_cvcolumnlist (cvid, columnindex, columnname) VALUES (?,?,?)', array($all->id, $idx++, $colname));
            }
        }
        echo "list view 'All' extended\n";
    }
} catch (Exception $e) { echo "list view extension skipped: " . $e->getMessage() . "\n"; }

echo "SETUP DONE\n";
