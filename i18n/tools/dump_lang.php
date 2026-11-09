<?php
// Dump a Vtiger language folder to JSON: {relpath: {var: {key: value}}}. Run with php (CLI).
// usage: php dump_lang.php /var/www/html/languages/en_us > en_us.json
$root = rtrim($argv[1], '/'); $out = array();
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    if (substr($f, -4) !== '.php') continue;
    $rel = substr($f, strlen($root) + 1);
    $languageStrings = $jsLanguageStrings = $mod_strings = null;
    include $f;
    foreach (array('languageStrings', 'jsLanguageStrings', 'mod_strings') as $v) {
        if (is_array($$v)) $out[$rel][$v] = $$v;
    }
}
ksort($out);
echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
