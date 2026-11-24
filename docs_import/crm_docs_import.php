<?php
/**
 * SamenCRM – student documents import (step 4): new fields, contacts, documents, tags.
 * Run INSIDE the web container of the student instance:
 *   php /opt/ss/docs/crm_docs_import.php setup  /opt/ss/docs/import.json     # block, fields, picklists, document folders
 *   php /opt/ss/docs/crm_docs_import.php import /opt/ss/docs/import.json [--limit N] [--only S0001,S0002] [--no-files]
 *
 * import.json is written by make_import.py:  {fields:[…], folders:[…], students:[{sid, contactid|null, create:{…},
 *   set:{…}, set_if_empty:{…}, tags:[…], documents:[{md5, file, name, title, folder, tags, description}]}]}
 * Files lie in /opt/ss/docs/files/<md5><ext>.
 *
 * - Existing contacts are updated with SQL (no workflow / e-mail / Telegram fires); "set_if_empty" never replaces a
 *   value typed in the CRM.  New contacts are created the Vtiger way.
 * - Every document is a Documents record with its file, linked to the contact, in the folder of its type, tagged.
 * - Resumable and idempotent: table samen_docs_import remembers student -> contact and file -> document.
 */
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING);
ini_set('memory_limit', '1024M');
chdir(getenv('VTIGER_ROOT') ?: '/var/www/html');
require_once 'vendor/autoload.php';
require_once 'config.php';
require_once 'include/utils/utils.php';
vimport('includes.runtime.EntryPoint');
require_once 'vtlib/Vtiger/Module.php';
require_once 'modules/Users/Users.php';
require_once 'modules/Documents/Documents.php';
require_once 'modules/Contacts/Contacts.php';

global $adb, $current_user, $log;
$adb = PearDatabase::getInstance();
$current_user = Users::getActiveAdminUser();
vglobal('current_user', $current_user);

$mode = $argv[1] ?? '';
$file = $argv[2] ?? '/opt/ss/docs/import.json';
$data = json_decode(file_get_contents($file), true);
if (!$data) { fwrite(STDERR, "bad $file\n"); exit(2); }
$FILES = dirname($file) . '/files/';
$opt = array('limit' => 0, 'only' => array(), 'nofiles' => in_array('--no-files', $argv));
foreach ($argv as $i => $a) {
    if ($a === '--limit') { $opt['limit'] = (int)$argv[$i + 1]; }
    if ($a === '--only') { $opt['only'] = explode(',', $argv[$i + 1]); }
}

$adb->pquery("CREATE TABLE IF NOT EXISTS samen_docs_import (
    kind VARCHAR(10) NOT NULL, k1 VARCHAR(64) NOT NULL, k2 VARCHAR(64) NOT NULL DEFAULT '', crmid INT NOT NULL,
    created DATETIME NOT NULL, PRIMARY KEY (kind, k1, k2)) DEFAULT CHARSET=utf8", array());

function q1($sql, $p = array()) { global $adb; $r = $adb->pquery($sql, $p); return $adb->num_rows($r) ? $adb->query_result($r, 0, 0) : null; }
function dec($v) { return html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8'); }

/* ------------------------------------------------------------------ setup */
function setup($data) {
    global $adb;
    $module = Vtiger_Module::getInstance('Contacts');
    $made = 0;
    foreach ($data['blocks'] as $bdef) {
        $block = Vtiger_Block::getInstance($bdef['label'], $module);
        if (!$block) { $block = new Vtiger_Block(); $block->label = $bdef['label']; $module->addBlock($block); echo "block: {$bdef['label']}\n"; }
        foreach ($bdef['fields'] as $f) {
            $field = Vtiger_Field::getInstance($f['name'], $module);
            if (!$field) {
                $field = new Vtiger_Field();
                $field->name = $f['name']; $field->column = $f['name']; $field->label = $f['label'];
                $field->table = 'vtiger_contactscf'; $field->typeofdata = 'V~O';
                if ($f['kind'] === 'picklist') { $field->uitype = 16; $field->columntype = 'VARCHAR(255)'; }
                elseif ($f['kind'] === 'long') { $field->uitype = 21; $field->columntype = 'TEXT'; }
                elseif ($f['kind'] === 'date') { $field->uitype = 5; $field->columntype = 'DATE'; $field->typeofdata = 'D~O'; }
                else { $field->uitype = 1; $field->columntype = 'VARCHAR(255)'; }
                $block->addField($field);
                if ($f['kind'] === 'picklist') { $field->setPicklistValues($f['values']); }
                $made++;
            }
        }
    }
    echo "fields created: $made\n";
    // vtiger_contactscf is near MySQL's 65,535-byte row limit (many VARCHAR(255) columns): when ADD COLUMN was refused,
    // the field exists without its column – add it as TEXT (kept off-row, no limit)
    $fixed = 0;
    foreach ($data['blocks'] as $bdef) {
        foreach ($bdef['fields'] as $f) {
            if (!q1("SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name='vtiger_contactscf' AND column_name=?", array($f['name']))) {
                $adb->pquery("ALTER TABLE vtiger_contactscf ADD COLUMN `{$f['name']}` " . ($f['kind'] === 'date' ? 'DATE' : 'TEXT') . " NULL", array());
                $fixed++;
            }
        }
    }
    if ($fixed) { echo "columns added as TEXT (row-size limit): $fixed\n"; }
    foreach ($data['picklists'] as $name => $values) {              // top up picklists (new universities, majors, …)
        $field = Vtiger_Field::getInstance($name, $module);
        if (!$field) { continue; }
        $have = array();
        $r = $adb->pquery("SELECT $name AS v FROM vtiger_$name", array());
        while ($row = $adb->fetch_array($r)) { $have[dec($row['v'])] = 1; }
        $missing = array_values(array_filter($values, function ($v) use ($have) { return $v !== '' && !isset($have[$v]); }));
        if ($missing) { $field->setPicklistValues($missing); echo "picklist $name: +" . count($missing) . "\n"; }
    }
    foreach ($data['folders'] as $name) { folder_id($name); }
    echo "document folders: " . q1('SELECT COUNT(*) FROM vtiger_attachmentsfolder') . "\n";
}

function folder_id($name) {
    global $adb, $current_user;
    static $cache = array();
    if (isset($cache[$name])) { return $cache[$name]; }
    $id = q1('SELECT folderid FROM vtiger_attachmentsfolder WHERE foldername=?', array($name));
    if (!$id) {
        $id = $adb->getUniqueID('vtiger_attachmentsfolder');
        $seq = (int)q1('SELECT COALESCE(MAX(sequence),0)+1 FROM vtiger_attachmentsfolder');
        $adb->pquery('INSERT INTO vtiger_attachmentsfolder (folderid, foldername, description, createdby, sequence) VALUES (?,?,?,?,?)',
            array($id, $name, 'مدارک دانشجویان', $current_user->id, $seq));
    }
    return $cache[$name] = $id;
}

function tag_id($name) {
    global $adb, $current_user;
    static $cache = array();
    $name = mb_substr(trim($name), 0, 50);
    if (isset($cache[$name])) { return $cache[$name]; }
    $id = q1('SELECT id FROM vtiger_freetags WHERE tag=?', array($name));
    if (!$id) {
        $id = $adb->getUniqueId('vtiger_freetags');
        $adb->pquery('INSERT INTO vtiger_freetags (id, tag, raw_tag, visibility, owner) VALUES (?,?,?,?,?)', array($id, $name, $name, 'public', $current_user->id));
    }
    return $cache[$name] = $id;
}

function tag($crmid, $module, $tags, $replacePrefixes = array()) {
    global $adb, $current_user;
    foreach ($replacePrefixes as $pre) {       // one value per kind of tag ("مدارک: ناقص" -> "مدارک: کامل")
        $adb->pquery('DELETE o FROM vtiger_freetagged_objects o JOIN vtiger_freetags t ON t.id=o.tag_id WHERE o.object_id=? AND t.tag LIKE ?', array($crmid, $pre . '%'));
    }
    foreach (array_unique($tags) as $t) {
        if (trim($t) === '') { continue; }
        $adb->pquery('INSERT INTO vtiger_freetagged_objects (tag_id, tagger_id, object_id, tagged_on, module) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE tag_id=tag_id',
            array(tag_id($t), $current_user->id, $crmid, date('Y-m-d H:i:s'), $module));
    }
}

/* ------------------------------------------------------------------ contacts */
$COLS = null;
function column_of($field) {
    global $adb, $COLS;
    if ($COLS === null) {
        $COLS = array();
        $r = $adb->pquery("SELECT fieldname, columnname, tablename FROM vtiger_field WHERE tabid=?", array(getTabid('Contacts')));
        while ($row = $adb->fetch_array($r)) { $COLS[$row['fieldname']] = array($row['tablename'], $row['columnname']); }
    }
    return $COLS[$field] ?? null;
}
$KEYCOL = array('vtiger_contactdetails' => 'contactid', 'vtiger_contactscf' => 'contactid', 'vtiger_contactsubdetails' => 'contactsubscriptionid',
    'vtiger_contactaddress' => 'contactaddressid', 'vtiger_crmentity' => 'crmid', 'vtiger_customerdetails' => 'customerid');

function current_value($cid, $field) {
    global $KEYCOL;
    $c = column_of($field); if (!$c) { return null; }
    return dec(q1("SELECT {$c[1]} FROM {$c[0]} WHERE {$KEYCOL[$c[0]]}=?", array($cid)));
}

function write_field($cid, $field, $value) {
    global $adb, $KEYCOL;
    $c = column_of($field); if (!$c) { echo "  ! unknown field $field\n"; return false; }
    $adb->pquery("UPDATE {$c[0]} SET {$c[1]}=? WHERE {$KEYCOL[$c[0]]}=?", array($value === '' ? null : $value, $cid));
    return true;
}

function update_contact($cid, $s) {
    global $adb;
    $n = 0;
    foreach ($s['set_if_empty'] as $f => $v) {
        if ($v === '' || $v === null) { continue; }
        $cur = current_value($cid, $f);
        if ($cur === null || trim($cur) === '' || $cur === '0000-00-00') { if (write_field($cid, $f, $v)) { $n++; } }
    }
    foreach ($s['set'] as $f => $v) { if (write_field($cid, $f, $v)) { $n++; } }
    if (!empty($s['append_description'])) {
        // one block of the import's notes at the end of the description; an older copy of it (also the HTML-encoded
        // form Vtiger stores) is replaced, what was written before it is kept
        $cur = (string)q1('SELECT description FROM vtiger_crmentity WHERE crmid=?', array($cid));
        $pos = mb_strpos($cur, $s['description_marker']);
        if ($pos !== false) { $cur = rtrim(mb_substr($cur, 0, $pos)); }
        $adb->pquery('UPDATE vtiger_crmentity SET description=? WHERE crmid=?', array(trim($cur . "\n\n" . $s['append_description']), $cid));
    }
    $adb->pquery('UPDATE vtiger_crmentity SET modifiedtime=?, modifiedby=1 WHERE crmid=?', array(date('Y-m-d H:i:s'), $cid));
    return $n;
}

function create_contact($s) {
    global $current_user;
    $focus = CRMEntity::getInstance('Contacts');
    foreach ($s['create'] as $f => $v) { $focus->column_fields[$f] = $v; }
    $focus->column_fields['assigned_user_id'] = $s['owner'] ?: $current_user->id;
    $focus->save('Contacts');
    return (int)$focus->id;
}

/* ------------------------------------------------------------------ documents */
function add_document($cid, $d, $owner) {
    global $adb, $current_user, $FILES, $upload_badext;
    $src = $FILES . $d['file'];
    if (!is_file($src)) { return array(0, 'file missing'); }
    $ext = strtolower(pathinfo($d['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, array('jpg', 'jpeg', 'png', 'pdf', 'docx', 'doc', 'xlsx', 'xls', 'rar', 'zip'))) { return array(0, 'type not allowed'); }
    $_FILES = array();
    $focus = CRMEntity::getInstance('Documents');
    $focus->column_fields['notes_title'] = $d['title'];
    $focus->column_fields['filename'] = $d['name'];
    $focus->column_fields['filetype'] = $d['mime'];
    $focus->column_fields['filesize'] = filesize($src);
    $focus->column_fields['filelocationtype'] = 'I';
    $focus->column_fields['filestatus'] = 1;
    $focus->column_fields['fileversion'] = '';
    $focus->column_fields['folderid'] = folder_id($d['folder']);
    $focus->column_fields['notecontent'] = $d['description'];
    $focus->column_fields['assigned_user_id'] = $owner ?: $current_user->id;
    $focus->column_fields['source'] = 'DOCS-IMPORT';
    try { $focus->save('Documents'); } catch (Exception $e) { return array(0, 'save: ' . $e->getMessage()); }
    $nid = (int)$focus->id;
    if (!$nid) { return array(0, 'no id'); }
    // The attachment, written as CRMEntity::uploadAndSaveFile does – without its upload check: that check refuses any
    // image whose bytes contain "<?" (most scans do, by chance or in their XMP metadata) and re-compresses JPEGs.
    // These files come from the office's own folders, their types are limited above.
    $attid = $adb->getUniqueID('vtiger_crmentity');
    $path = decideFilePath();
    $stored = Vtiger_Util_Helper::getEncryptedFileName(sanitizeUploadFileName($d['name'], $upload_badext));
    if (!copy($src, $path . $attid . '_' . $stored)) {
        $adb->pquery('UPDATE vtiger_crmentity SET deleted=1 WHERE crmid=?', array($nid));
        return array(0, 'cannot copy into storage');
    }
    $now = date('Y-m-d H:i:s');
    $adb->pquery('INSERT INTO vtiger_crmentity (crmid,smcreatorid,smownerid,setype,description,createdtime,modifiedtime) VALUES (?,?,?,?,?,?,?)',
        array($attid, $current_user->id, $owner ?: $current_user->id, 'Documents Attachment', '', $now, $now));
    $adb->pquery('INSERT INTO vtiger_attachments (attachmentsid, name, description, type, path, storedname) VALUES (?,?,?,?,?,?)',
        array($attid, $d['name'], '', $d['mime'], $path, $stored));
    $adb->pquery('INSERT INTO vtiger_seattachmentsrel VALUES (?,?)', array($nid, $attid));
    $adb->pquery('UPDATE vtiger_notes SET filename=?, filesize=?, filetype=?, filelocationtype=?, filestatus=1 WHERE notesid=?',
        array($d['name'], filesize($src), $d['mime'], 'I', $nid));
    $adb->pquery('INSERT IGNORE INTO vtiger_senotesrel (crmid, notesid) VALUES (?,?)', array($cid, $nid));
    tag($nid, 'Documents', $d['tags']);
    return array($nid, '');
}

/* ------------------------------------------------------------------ run */
if ($mode === 'setup') { setup($data); exit(0); }
if ($mode !== 'import') { exit("usage: crm_docs_import.php setup|import import.json\n"); }

$done = $new = $upd = $docs = $docerr = 0; $t0 = time();
foreach ($data['students'] as $s) {
    if ($opt['only'] && !in_array($s['sid'], $opt['only'])) { continue; }
    if ($opt['limit'] && $done >= $opt['limit']) { break; }
    $cid = (int)q1("SELECT crmid FROM samen_docs_import WHERE kind='student' AND k1=?", array($s['sid']));
    if ($cid && !q1('SELECT crmid FROM vtiger_crmentity WHERE crmid=? AND deleted=0', array($cid))) { $cid = 0; }
    if (!$cid) {
        if (!empty($s['contactid'])) {
            $cid = (int)$s['contactid'];
            if (!q1("SELECT crmid FROM vtiger_crmentity WHERE crmid=? AND deleted=0 AND setype='Contacts'", array($cid))) { echo "{$s['sid']}: contact $cid not found – skipped\n"; continue; }
        } else {
            $cid = create_contact($s);
            if (!$cid) { echo "{$s['sid']}: could not create the contact\n"; continue; }
            $new++;
        }
        $adb->pquery("REPLACE INTO samen_docs_import (kind, k1, k2, crmid, created) VALUES ('student', ?, '', ?, ?)", array($s['sid'], $cid, date('Y-m-d H:i:s')));
    }
    update_contact($cid, $s); $upd++;
    tag($cid, 'Contacts', $s['tags'], $s['tag_prefixes']);
    if (!$opt['nofiles']) {
        foreach ($s['documents'] as $d) {
            if (q1("SELECT crmid FROM samen_docs_import WHERE kind='doc' AND k1=? AND k2=?", array($d['md5'], (string)$cid))) { continue; }
            list($nid, $err) = add_document($cid, $d, $s['owner']);
            if ($nid) {
                $adb->pquery("REPLACE INTO samen_docs_import (kind, k1, k2, crmid, created) VALUES ('doc', ?, ?, ?, ?)", array($d['md5'], (string)$cid, $nid, date('Y-m-d H:i:s')));
                $docs++;
            } else { $docerr++; echo "  {$s['sid']} {$d['name']}: $err\n"; }
        }
    }
    $done++;
    if ($done % 25 === 0) { echo "$done students, $new new, $docs documents, $docerr file errors, " . (time() - $t0) . "s\n"; }
}
// the assignment notification records Vtiger writes for every new contact (as post_install.sh does)
$adb->pquery("UPDATE vtiger_crmentity e JOIN vtiger_activity a ON a.activityid=e.crmid SET e.deleted=1
              WHERE e.setype='Emails' AND a.subject='Regarding Contact Assignment' AND e.deleted=0", array());
echo "DONE: $done students ($new created, " . ($upd - $new) . " existing updated), $docs documents added, $docerr file errors, " . (time() - $t0) . "s\n";
