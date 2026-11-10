<?php
/**
 * Mock Telegram Bot API for end-to-end tests (no network needed):
 *   php -S 127.0.0.1:8099 tests/mock_telegram.php
 * Point the module at it with api_base = http://127.0.0.1:8099.
 *
 *   /bot<token>/<method>      getMe, getUpdates, sendMessage, sendPhoto, sendDocument, sendVideo, sendAudio,
 *                             getFile, getWebhookInfo, deleteWebhook
 *   /file/bot<token>/<path>   file download
 *   /_control/push            POST JSON "message" object -> queued as an update
 *   /_control/calls           JSON log of all API calls;  /_control/reset
 * Special chat ids: 403 -> blocked, 429 -> first call rate-limited (retry_after 1), 500 -> server error.
 * Token "1:BADTOKENBADTOKENBADTOKEN" -> 401.
 */
$dir = sys_get_temp_dir() . '/tgmock';
if (!is_dir($dir)) { mkdir($dir, 0777, true); }
$stateFile = "$dir/state.json";
$fh = fopen("$dir/lock", 'c'); flock($fh, LOCK_EX);
$state = is_file($stateFile) ? json_decode(file_get_contents($stateFile), true) : null;
if (!$state) { $state = array('updates' => array(), 'next_update' => 1000, 'next_msg' => 1, 'calls' => array(), 'flags' => array()); }
function save() { global $state, $stateFile; file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE)); }
function out($data, $code = 200) { http_response_code($code); header('Content-Type: application/json'); echo json_encode($data, JSON_UNESCAPED_UNICODE); }
function fail($code, $desc, $extra = array()) { out(array('ok' => false, 'error_code' => $code, 'description' => $desc) + $extra, $code); }

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path == '/_control/reset') { $state = null; @unlink($stateFile); out(array('ok' => true)); exit; }
if ($path == '/_control/calls') { out($state['calls']); exit; }
if ($path == '/_control/push') {
	$msg = json_decode(file_get_contents('php://input'), true);
	$msg += array('message_id' => $state['next_msg']++, 'date' => time());
	$state['updates'][] = array('update_id' => $state['next_update']++, 'message' => $msg);
	save(); out(array('ok' => true)); exit;
}
if (preg_match('#^/file/bot[^/]+/(.+)$#', $path, $m)) { header('Content-Type: application/octet-stream'); echo "MOCKFILE:" . $m[1]; exit; }
if (!preg_match('#^/bot([^/]+)/(\w+)$#', $path, $m)) { fail(404, 'Not Found'); exit; }
$token = $m[1]; $method = $m[2];
$p = $_POST + $_GET;
$files = array();
foreach ($_FILES as $k => $f) { $files[$k] = array('name' => $f['name'], 'size' => $f['size'], 'type' => $f['type']); }
$state['calls'][] = array('method' => $method, 'params' => $p, 'files' => $files, 't' => microtime(true));
if ($token == '1:BADTOKENBADTOKENBADTOKEN' || $token == '0:test') { save(); fail(401, 'Unauthorized'); exit; }

$chat = isset($p['chat_id']) ? (string)$p['chat_id'] : '';
if (strpos($method, 'send') === 0) {
	if ($chat == '403') { save(); fail(403, 'Forbidden: bot was blocked by the user'); exit; }
	if ($chat == '500') { save(); fail(500, 'Internal Server Error'); exit; }
	if ($chat == '429' && empty($state['flags']['429'])) { $state['flags']['429'] = 1; save(); fail(429, 'Too Many Requests: retry after 1', array('parameters' => array('retry_after' => 1))); exit; }
}
$msg = array('message_id' => $state['next_msg']++, 'date' => time(), 'chat' => array('id' => (int)$chat, 'type' => 'private'));
switch ($method) {
	case 'getMe': $r = array('id' => 999000, 'is_bot' => true, 'first_name' => 'Samen Test Bot', 'username' => 'samen_test_bot'); break;
	case 'getWebhookInfo': $r = array('url' => '', 'pending_update_count' => 0); break;
	case 'deleteWebhook': $r = true; break;
	case 'getUpdates':
		$off = isset($p['offset']) ? (int)$p['offset'] : 0;
		$state['updates'] = array_values(array_filter($state['updates'], function ($u) use ($off) { return $u['update_id'] >= $off; }));
		$r = $state['updates'];
		if (!$r && !empty($p['timeout'])) { save(); flock($fh, LOCK_UN); sleep(1); out(array('ok' => true, 'result' => array())); exit; }
		break;
	case 'getFile': $r = array('file_id' => $p['file_id'], 'file_size' => 9, 'file_path' => 'photos/' . preg_replace('/\W/', '', $p['file_id']) . '.jpg'); break;
	case 'sendMessage': $r = $msg + array('text' => $p['text']); break;
	case 'sendPhoto': $fid = isset($files['photo']) ? 'PHOTO_' . $msg['message_id'] : $p['photo'];
		$r = $msg + array('photo' => array(array('file_id' => $fid . '_s', 'width' => 90), array('file_id' => $fid, 'width' => 800))); break;
	case 'sendDocument': case 'sendVideo': case 'sendAudio':
		$k = strtolower(substr($method, 4));
		$fid = isset($files[$k]) ? strtoupper($k) . '_' . $msg['message_id'] : $p[$k];
		$r = $msg + array($k => array('file_id' => $fid)); break;
	default: save(); fail(404, 'Not Found: method not found'); exit;
}
save();
out(array('ok' => true, 'result' => $r));
