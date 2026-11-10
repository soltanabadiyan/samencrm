<?php
/**
 * TelegramBot end-to-end test against the mock Bot API (tests/mock_telegram.php on 127.0.0.1:8099).
 * Run INSIDE the web container as www-data (run_tests.sh does all of this):
 *   php tests/e2e_test.php
 * Creates 4 temporary test contacts, a test tag and a test bot, exercises linking (/start deep link, phone),
 * incoming messages + media, single/bulk sends (tag, group, picklist, filter, list ids), media upload + file_id reuse,
 * scheduling, birthdays, merge fields, emoji, long texts, 429/403/500 handling, opt-out, rendering of the views;
 * then removes everything it created and restores the settings.
 */
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED & ~E_WARNING & ~E_STRICT);
ini_set('display_errors', '1');
chdir(getenv('VTIGER_ROOT') ?: '/var/www/html');
require_once 'vendor/autoload.php';
require_once 'config.php';
require_once 'include/utils/utils.php';
vimport('includes.runtime.EntryPoint');
require_once 'modules/Users/Users.php';
require_once 'modules/TelegramBot/lib/Access.php';
require_once 'modules/TelegramBot/actions/Ajax.php';

global $adb, $current_user;
$adb = PearDatabase::getInstance();
$current_user = Users::getActiveAdminUser();
vglobal('current_user', $current_user);
$MOCK = getenv('TG_MOCK') ?: 'http://127.0.0.1:8099';

$pass = 0; $failures = array();
function check($cond, $name, $detail = '') {
	global $pass, $failures;
	if ($cond) { $pass++; echo "  PASS  $name\n"; } else { $failures[] = $name; echo "  FAIL  $name" . ($detail !== '' ? "  -> $detail" : '') . "\n"; }
}
function mock($path, $data = null) {
	global $MOCK;
	$ch = curl_init($MOCK . $path);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	if ($data !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data)); curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: application/json')); }
	$r = curl_exec($ch); curl_close($ch);
	return json_decode($r, true);
}
function calls($method = null, $chat = null) {
	$out = array();
	foreach (mock('/_control/calls') as $c) {
		if ($method && $c['method'] != $method) { continue; }
		if ($chat !== null && (!isset($c['params']['chat_id']) || $c['params']['chat_id'] != $chat)) { continue; }
		$out[] = $c;
	}
	return $out;
}
function push($chat, $msg) { return mock('/_control/push', $msg + array('chat' => array('id' => $chat, 'type' => 'private'), 'from' => array('id' => $chat, 'first_name' => 'Test' . $chat, 'username' => 'u' . $chat))); }
function req($p) { return new Vtiger_Request($p, $p); }
function ajax($mode, $p) { $a = new TelegramBot_Ajax_Action(); return $a->$mode(req($p)); }
function poll() { return TelegramBot_Service::runWorker(array('seconds' => 8, 'poll' => true, 'longpoll' => false)); }
function q1($sql, $p = array()) { return TelegramBot_Store::row($sql, $p); }

echo "== setup\n";
if (!mock('/_control/reset')) { echo "mock Bot API not reachable at $MOCK\n"; exit(2); }
$saved = TelegramBot_Store::settings(true);
foreach (array('api_base' => $MOCK, 'proxy' => '', 'rate_per_sec' => '30', 'birthday_enabled' => '1', 'birthday_botid' => '') as $k => $v) { TelegramBot_Store::set($k, $v); }

$created = array('contacts' => array(), 'bot' => 0, 'tag' => 0, 'docs' => array());
try {
	// the test bot must be the default for this run; remember the old default
	$oldDefault = q1('SELECT id FROM vtiger_telegrambot_bots WHERE is_default=1');
	$r = ajax('saveBot', array('title' => 'TEST BOT', 'token' => '123456:TESTTOKENTESTTOKENTESTTOKEN'));
	$created['bot'] = $r['id'];
	$adb->pquery('UPDATE vtiger_telegrambot_bots SET is_default=(id=?)', array($r['id']));
	$bot = TelegramBot_Store::bot($r['id']);
	check($bot['username'] == 'samen_test_bot', 'saveBot reads username via getMe', $bot['username'] . ' ' . $bot['last_error']);
	$t = ajax('testBot', array('id' => $bot['id']));
	check($t['username'] == 'samen_test_bot', 'testBot');
	$bad = ajax('testProxy', array('api_base' => $MOCK, 'proxy' => ''));
	check($bad['reachable'], 'testProxy reachable');
	$bad = ajax('testProxy', array('api_base' => 'http://127.0.0.1:1', 'proxy' => ''));
	check(!$bad['reachable'], 'testProxy unreachable detected');

	// a level value + its group, a major value
	$lvl = q1("SELECT cf_ss_level v FROM vtiger_contactscf WHERE cf_ss_level<>'' GROUP BY 1 ORDER BY COUNT(*) DESC LIMIT 1");
	$grp = q1("SELECT groupid FROM vtiger_groups ORDER BY groupid DESC LIMIT 1");
	$userFmtDate = DateTimeField::convertToUserFormat(date('Y-m-d', strtotime('-20 years -40 days')));
	$today = new DateTime('now', new DateTimeZone(TelegramBot_Store::get('timezone')));
	$bday = DateTimeField::convertToUserFormat((intval($today->format('Y')) - 22) . $today->format('-m-d'));
	$defs = array(
		array('firstname' => 'تست‌یک', 'lastname' => 'TGTEST', 'mobile' => '+964 770 111 2233', 'birthday' => $bday),
		array('firstname' => 'تست‌دو', 'lastname' => 'TGTEST', 'mobile' => '00964-770-765-4321', 'birthday' => $userFmtDate),
		array('firstname' => 'تست‌سه', 'lastname' => 'TGTEST', 'mobile' => '+98 912 000 0000', 'birthday' => $userFmtDate),
		array('firstname' => 'تست‌چهار', 'lastname' => 'TGTEST', 'mobile' => '', 'birthday' => $userFmtDate),
	);
	foreach ($defs as $d) {
		$rec = Vtiger_Record_Model::getCleanInstance('Contacts');
		foreach ($d as $k => $v) { $rec->set($k, $v); }
		$rec->set('cf_ss_level', html_entity_decode($lvl['v'], ENT_QUOTES, 'UTF-8'));
		$rec->set('cf_ss_major', 'TGTEST-MAJOR');
		$rec->set('assigned_user_id', $grp['groupid']);
		$rec->save();
		$created['contacts'][] = (int)$rec->getId();
	}
	list($c1, $c2, $c3, $c4) = $created['contacts'];
	check(count(array_filter($created['contacts'])) == 4, 'created 4 test contacts', implode(',', $created['contacts']));

	$tagId = $adb->getUniqueID('vtiger_freetags');
	$adb->pquery("INSERT INTO vtiger_freetags (id, tag, raw_tag, visibility, owner) VALUES (?,?,?,'public',?)", array($tagId, 'TGTEST-TAG', 'TGTEST-TAG', $current_user->id));
	$created['tag'] = $tagId;
	foreach (array($c1, $c2, $c3) as $cid) {
		$adb->pquery("INSERT INTO vtiger_freetagged_objects (tag_id, tagger_id, object_id, tagged_on, module) VALUES (?,?,?,NOW(),'Contacts')", array($tagId, $current_user->id, $cid));
	}

	echo "== linking\n";
	$deep = TelegramBot_Store::deepLink($bot, $c1);
	check(preg_match('#^https://t\.me/samen_test_bot\?start=c' . $c1 . '_[0-9a-f]{12}$#', $deep), 'deep link format', $deep);
	$token = substr($deep, strpos($deep, '=') + 1);
	push(1001, array('text' => '/start ' . $token));
	$w = poll();
	$l1 = TelegramBot_Store::link($bot['id'], $c1);
	check($l1 && $l1['chat_id'] == 1001 && $l1['linked_via'] == 'start', '/start <token> links chat to contact');
	$welcome = calls('sendMessage', 1001);
	check($welcome && strpos($welcome[0]['params']['text'], 'تست‌یک') !== false, 'welcome message with $firstname$ merged', isset($welcome[0]) ? $welcome[0]['params']['text'] : 'none');

	push(1002, array('text' => '/start'));
	poll();
	$ask = calls('sendMessage', 1002);
	check($ask && strpos($ask[0]['params']['reply_markup'], 'request_contact') !== false, '/start without token asks for phone (contact button)');
	push(1002, array('contact' => array('phone_number' => '+9647707654321', 'user_id' => 1002, 'first_name' => 'X')));
	push(1003, array('contact' => array('phone_number' => '+9647707654321', 'user_id' => 5555, 'first_name' => 'someone else')));
	push(1004, array('contact' => array('phone_number' => '+1 555 0100', 'user_id' => 1004)));
	poll();
	$l2 = TelegramBot_Store::link($bot['id'], $c2);
	check($l2 && $l2['chat_id'] == 1002 && $l2['linked_via'] == 'phone', 'shared own phone number links contact (+964 / 00964 normalisation)');
	check(!TelegramBot_Store::linksForChat($bot['id'], 1003), 'foreign contact card is not used for linking');
	$nf = calls('sendMessage', 1004);
	check($nf && end($nf)['params']['text'] == TelegramBot_Store::get('not_found_text'), 'unknown phone -> not-found reply');

	echo "== incoming\n";
	push(1001, array('text' => 'سلام، مدارکم را فرستادم 🙏'));
	push(1001, array('photo' => array(array('file_id' => 'INPHOTO_s', 'file_size' => 5), array('file_id' => 'INPHOTO', 'file_size' => 9)), 'caption' => 'passport'));
	push(1001, array('document' => array('file_id' => 'INDOC', 'file_name' => 'transcript.pdf', 'mime_type' => 'application/pdf', 'file_size' => 9)));
	push(1009, array('text' => 'hello from an unknown chat'));
	poll();
	$h = TelegramBot_Store::history($c1);
	$in = array_values(array_filter($h, function ($m) { return $m['direction'] == 'in'; }));
	$txt = array_values(array_filter($in, function ($m) { return $m['msg_type'] == 'text' && strpos($m['body'], 'مدارکم') !== false; }));
	check($txt && strpos($txt[0]['body'], '🙏') !== false, 'incoming text stored with emoji');
	$ph = array_values(array_filter($in, function ($m) { return $m['msg_type'] == 'photo'; }));
	check($ph && $ph[0]['body'] == 'passport' && TelegramBot_Store::absPath($ph[0]['media_path']) && file_get_contents(TelegramBot_Store::absPath($ph[0]['media_path'])) == 'MOCKFILE:photos/INPHOTO.jpg', 'incoming photo downloaded (largest size) with caption');
	$doc = array_values(array_filter($in, function ($m) { return $m['msg_type'] == 'document'; }));
	check($doc && $doc[0]['media_name'] == 'transcript.pdf' && TelegramBot_Store::absPath($doc[0]['media_path']), 'incoming document downloaded with file name');
	$unk = q1("SELECT * FROM vtiger_telegrambot_messages WHERE botid=? AND chat_id=1009 AND direction='in'", array($bot['id']));
	check($unk && $unk['contactid'] == 0, 'message from unlinked chat kept (contactid 0)');
	check(calls('sendMessage', 1009), 'unlinked chat gets the phone prompt');

	echo "== single send\n";
	$r = ajax('send', array('botid' => $bot['id'], 'target_type' => 'ids', 'record' => $c1, 'body' => 'سلام $firstname$ $lastname$ – رشته: $major$ 🎓', 'when' => 'now'));
	check($r['single'] && $r['status'] == 'sent', 'single send now -> sent', json_encode($r, JSON_UNESCAPED_UNICODE));
	$last = calls('sendMessage', 1001); $last = end($last);
	check($last['params']['text'] == 'سلام تست‌یک TGTEST – رشته: TGTEST-MAJOR 🎓', 'merge fields + emoji in sent text', $last['params']['text']);
	try { ajax('send', array('botid' => $bot['id'], 'target_type' => 'ids', 'record' => $c3, 'body' => 'x', 'when' => 'now')); $e = ''; } catch (Exception $ex) { $e = $ex->getMessage(); }
	check(strpos($e, 't.me/samen_test_bot?start=') !== false, 'single send to unlinked contact explains + gives deep link');
	$long = str_repeat('متن طولانی ', 500);
	$before = count(calls('sendMessage', 1001));
	ajax('send', array('botid' => $bot['id'], 'target_type' => 'ids', 'record' => $c1, 'body' => $long, 'when' => 'now'));
	check(count(calls('sendMessage', 1001)) - $before == 2, 'text > 4096 chars split into 2 messages');

	echo "== media\n";
	$tmp = tempnam(sys_get_temp_dir(), 'tg'); file_put_contents($tmp, base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA='));
	$_FILES['media_file'] = array('name' => 'poster.jpg', 'type' => 'image/jpeg', 'tmp_name' => $tmp, 'error' => 0, 'size' => filesize($tmp));
	$r = ajax('send', array('botid' => $bot['id'], 'target_type' => 'tag', 'tags' => array($tagId), 'body' => 'پوستر برای $firstname$', 'media_source' => 'upload', 'when' => 'now', 'title' => 'TGTEST photo'));
	unset($_FILES['media_file']);
	$photos = array_values(array_filter(calls('sendPhoto'), function ($c) { return in_array($c['params']['chat_id'], array(1001, 1002)); }));
	check(count($photos) == 2 && $r['total'] == 2 && $r['skipped'] == 1, 'bulk photo by tag: 2 linked sent, 1 not linked skipped', json_encode($r));
	check($photos && !empty($photos[0]['files']['photo']) && empty($photos[1]['files']) && strpos($photos[1]['params']['photo'], 'PHOTO_') === 0, 'photo uploaded once, then reused by file_id');
	check($photos && strpos($photos[0]['params']['caption'], 'پوستر برای تست') === 0, 'caption merged per recipient');

	// Vtiger Document as attachment
	$docRec = Vtiger_Record_Model::getCleanInstance('Documents');
	$docRec->set('notes_title', 'TGTEST document'); $docRec->set('filelocationtype', 'I'); $docRec->set('filestatus', 1); $docRec->set('assigned_user_id', $current_user->id);
	$docRec->save();
	$created['docs'][] = $docRec->getId();
	$attId = $adb->getUniqueID('vtiger_crmentity');
	$sub = 'storage/TGTEST/'; @mkdir($sub, 0775, true);
	file_put_contents($sub . $attId . '_guide.pdf', '%PDF-1.4 test');
	$adb->pquery("INSERT INTO vtiger_crmentity (crmid, smcreatorid, smownerid, setype, createdtime, modifiedtime) VALUES (?,?,?,'Documents Attachment',NOW(),NOW())", array($attId, 1, 1));
	$adb->pquery('INSERT INTO vtiger_attachments (attachmentsid, name, type, path, storedname) VALUES (?,?,?,?,?)', array($attId, 'guide.pdf', 'application/pdf', $sub, 'guide.pdf'));
	$adb->pquery('INSERT INTO vtiger_seattachmentsrel (crmid, attachmentsid) VALUES (?,?)', array($docRec->getId(), $attId));
	$adb->pquery("UPDATE vtiger_notes SET filename='guide.pdf', filetype='application/pdf', filesize=13 WHERE notesid=?", array($docRec->getId()));
	$found = ajax('searchDocuments', array('q' => 'TGTEST'));
	check($found && $found[0]['id'] == $docRec->getId(), 'searchDocuments finds the document');
	$r = ajax('send', array('botid' => $bot['id'], 'target_type' => 'ids', 'record' => $c2, 'body' => 'راهنما', 'media_source' => 'document', 'docid' => $docRec->getId(), 'when' => 'now'));
	$d = calls('sendDocument', 1002);
	check($r['status'] == 'sent' && $d && $d[0]['files']['document']['name'] == 'guide.pdf', 'send a Vtiger Document as file', json_encode($r));

	echo "== bulk targets\n";
	$cnt = ajax('count', array('botid' => $bot['id'], 'target_type' => 'group', 'groups' => array($grp['groupid'])));
	check($cnt['total'] >= 4 && $cnt['reachable'] >= 2, 'count by group (level group)', json_encode($cnt));
	$cnt = ajax('count', array('botid' => $bot['id'], 'target_type' => 'picklist', 'pl_field' => 'cf_ss_major', 'pl_values' => array('TGTEST-MAJOR')));
	check($cnt['total'] == 4 && $cnt['reachable'] == 2, 'count by picklist value (major)', json_encode($cnt));
	$cnt = ajax('count', array('botid' => $bot['id'], 'target_type' => 'tag', 'tags' => array($tagId)));
	check($cnt['total'] == 3 && $cnt['reachable'] == 2, 'count by tag', json_encode($cnt));
	$cv = q1("SELECT cvid FROM vtiger_customview WHERE entitytype='Contacts' AND viewname='All'");
	$cnt = ajax('count', array('botid' => $bot['id'], 'target_type' => 'filter', 'filters' => array($cv['cvid'])));
	check($cnt['total'] > 1000 && $cnt['reachable'] == 2, 'count by saved filter (All)', json_encode($cnt));
	$cnt = ajax('count', array('botid' => $bot['id'], 'target_type' => 'list', 'list' => json_encode(array('selected_ids' => array($c1, $c3), 'excluded_ids' => array(), 'viewname' => $cv['cvid']))));
	check($cnt['total'] == 2 && $cnt['reachable'] == 1, 'count by list-view selection', json_encode($cnt));
	$cnt = ajax('count', array('botid' => $bot['id'], 'target_type' => 'linked'));
	check($cnt['reachable'] == 2, 'count all linked', json_encode($cnt));
	$before = count(calls('sendMessage'));
	$r = ajax('send', array('botid' => $bot['id'], 'target_type' => 'picklist', 'pl_field' => 'cf_ss_major', 'pl_values' => array('TGTEST-MAJOR'), 'body' => 'اطلاعیه $level$', 'when' => 'now'));
	check($r['total'] == 2 && $r['skipped'] == 2 && ($r['stats']['sent'] ?? 0) == 2 && $r['status'] == 'done', 'bulk text by picklist sent + campaign done', json_encode($r));

	echo "== scheduling\n";
	$at = new DateTime('+2 hours', TelegramBot_Store::userTz());
	$r = ajax('send', array('botid' => $bot['id'], 'target_type' => 'tag', 'tags' => array($tagId), 'body' => 'یادآوری زمان‌بندی‌شده', 'when' => 'later',
		'send_date' => DateTimeField::convertToUserFormat($at->format('Y-m-d')), 'send_time' => $at->format('H:i'), 'title' => 'TGTEST scheduled'));
	$camp = q1('SELECT * FROM vtiger_telegrambot_campaigns WHERE id=?', array($r['campaign']));
	check($r['later'] && $camp['status'] == 'scheduled' && abs($camp['scheduled_at'] - $at->getTimestamp()) < 60, 'campaign scheduled at the picked date/time (user time zone)', json_encode($r));
	poll();
	check(q1('SELECT status FROM vtiger_telegrambot_campaigns WHERE id=?', array($r['campaign']))['status'] == 'scheduled', 'not sent before its time');
	$adb->pquery('UPDATE vtiger_telegrambot_campaigns SET scheduled_at=? WHERE id=?', array(time() - 5, $r['campaign']));
	poll();
	$camp = q1('SELECT * FROM vtiger_telegrambot_campaigns WHERE id=?', array($r['campaign']));
	$st = TelegramBot_Service::campaignStats(array($r['campaign']));
	check($camp['status'] == 'done' && ($st[$r['campaign']]['sent'] ?? 0) == 2, 'scheduled campaign sent by the worker when due', json_encode($camp));
	$r = ajax('send', array('botid' => $bot['id'], 'target_type' => 'tag', 'tags' => array($tagId), 'body' => 'لغو', 'when' => 'later',
		'send_date' => DateTimeField::convertToUserFormat($at->format('Y-m-d')), 'send_time' => $at->format('H:i')));
	ajax('cancelCampaign', array('id' => $r['campaign']));
	check(q1('SELECT status FROM vtiger_telegrambot_campaigns WHERE id=?', array($r['campaign']))['status'] == 'cancelled', 'cancel scheduled campaign');
	try { ajax('send', array('botid' => $bot['id'], 'target_type' => 'tag', 'tags' => array($tagId), 'body' => 'x', 'when' => 'later', 'send_date' => DateTimeField::convertToUserFormat(date('Y-m-d', strtotime('-2 days'))), 'send_time' => '10:00')); $e = ''; } catch (Exception $ex) { $e = $ex->getMessage(); }
	check($e !== '', 'schedule in the past rejected');

	echo "== birthdays\n";
	TelegramBot_Store::set('birthday_last', '');
	$n = TelegramBot_Service::runBirthdays(true);
	poll();
	$bc = q1("SELECT * FROM vtiger_telegrambot_campaigns WHERE kind='birthday' ORDER BY id DESC LIMIT 1");
	$bm = q1("SELECT * FROM vtiger_telegrambot_messages WHERE campaignid=? AND contactid=?", array($bc['id'], $c1));
	check($n >= 1 && $bm && $bm['status'] == 'sent' && strpos($bm['body'], 'تست‌یک') !== false, 'birthday message queued + sent to contact born today', "n=$n " . json_encode($bm, JSON_UNESCAPED_UNICODE));
	check(!q1("SELECT id FROM vtiger_telegrambot_messages WHERE campaignid=? AND contactid=?", array($bc['id'], $c2)), 'no birthday message for others');
	check(TelegramBot_Service::runBirthdays() == 0, 'birthdays run only once a day');

	echo "== errors, retry, rate limit, opt-out\n";
	$adb->pquery("INSERT INTO vtiger_telegrambot_links (botid, contactid, chat_id, linked_via, linked_at) VALUES (?,?,429,'start',?)", array($bot['id'], $c3, time()));
	$adb->pquery("INSERT INTO vtiger_telegrambot_links (botid, contactid, chat_id, linked_via, linked_at) VALUES (?,?,403,'start',?)", array($bot['id'], $c4, time()));
	$r = ajax('send', array('botid' => $bot['id'], 'target_type' => 'ids', 'record' => $c3, 'body' => 'rate limited', 'when' => 'now'));
	$m429 = q1("SELECT * FROM vtiger_telegrambot_messages WHERE chat_id=429 ORDER BY id DESC LIMIT 1");
	check($r['status'] == 'queued' && $m429['next_try'] > time() - 1 && strpos($m429['error'], '429') !== false, '429 -> stays queued with retry_after', json_encode($r));
	sleep(2);
	poll();
	check(TelegramBot_Store::message($m429['id'])['status'] == 'sent', 'queued message retried and sent');
	$r = ajax('send', array('botid' => $bot['id'], 'target_type' => 'ids', 'record' => $c4, 'body' => 'blocked', 'when' => 'now'));
	check($r['status'] == 'failed' && TelegramBot_Store::link($bot['id'], $c4)['blocked'] == 1, '403 -> failed and link marked blocked', json_encode($r));
	$adb->pquery("UPDATE vtiger_telegrambot_links SET chat_id=500, blocked=0 WHERE botid=? AND contactid=?", array($bot['id'], $c4));
	$r = ajax('send', array('botid' => $bot['id'], 'target_type' => 'ids', 'record' => $c4, 'body' => 'server error', 'when' => 'now'));
	$m500 = q1("SELECT * FROM vtiger_telegrambot_messages WHERE chat_id=500 ORDER BY id DESC LIMIT 1");
	check($r['status'] == 'queued' && $m500['attempts'] == 1 && $m500['next_try'] >= time() + 20, '5xx -> retry queue with backoff', json_encode($m500));
	$adb->pquery("UPDATE vtiger_telegrambot_messages SET attempts=4, next_try=0 WHERE id=?", array($m500['id']));
	poll();
	check(TelegramBot_Store::message($m500['id'])['status'] == 'failed', 'gives up after max attempts');
	$re = ajax('retryMessage', array('id' => $m500['id']));
	check($re['status'] == 'queued', 'manual retry re-queues');

	// rate limit: 12 messages to 2 chats -> per chat >= 1 s apart
	TelegramBot_Store::set('rate_per_sec', '10');
	$adb->pquery("DELETE FROM vtiger_telegrambot_messages WHERE chat_id=500 AND status='queued'", array());
	$cid = TelegramBot_Service::createCampaign(array('botid' => $bot['id'], 'body' => 'rate', 'target_type' => 'ids', 'target_value' => array(), 'status' => 'queued'));
	for ($i = 0; $i < 6; $i++) {
		foreach (array(array($c1, 1001), array($c2, 1002)) as $pair) {
			TelegramBot_Store::addMessage(array('botid' => $bot['id'], 'contactid' => $pair[0], 'chat_id' => $pair[1], 'body' => "rate $i", 'campaignid' => $cid));
		}
	}
	$t0 = microtime(true);
	TelegramBot_Service::runWorker(array('seconds' => 20, 'poll' => false));
	$sends = array_values(array_filter(calls('sendMessage'), function ($c) use ($t0) { return $c['t'] >= $t0; }));
	$byChat = array();
	foreach ($sends as $s) { $byChat[$s['params']['chat_id']][] = $s['t']; }
	$minGap = 99;
	foreach ($byChat as $ts) { for ($i = 1; $i < count($ts); $i++) { $minGap = min($minGap, $ts[$i] - $ts[$i - 1]); } }
	check(count($sends) == 12 && $minGap >= 1.0, 'per-chat limit: >= 1 s between messages to the same chat', 'sent=' . count($sends) . ' minGap=' . round($minGap, 2));
	TelegramBot_Store::set('rate_per_sec', '30');

	// opt-out
	push(1001, array('text' => '/stop'));
	poll();
	check(TelegramBot_Store::link($bot['id'], $c1)['optout'] == 1, '/stop sets opt-out');
	$r = ajax('send', array('botid' => $bot['id'], 'target_type' => 'tag', 'tags' => array($tagId), 'body' => 'after stop', 'when' => 'now'));
	check($r['total'] == 2 && !q1("SELECT id FROM vtiger_telegrambot_messages WHERE campaignid=? AND chat_id=1001", array($r['campaign'])), 'opted-out contact excluded from bulk', json_encode($r));
	push(1001, array('text' => '/start'));
	poll();
	check(TelegramBot_Store::link($bot['id'], $c1)['optout'] == 0, '/start again re-subscribes');
	ajax('setOptout', array('record' => $c2, 'botid' => $bot['id'], 'value' => 1));
	check(TelegramBot_Store::link($bot['id'], $c2)['optout'] == 1, 'opt-out flag from the CRM');

	echo "== templates\n";
	$tp = ajax('saveTemplate', array('title' => 'TGTEST template', 'body' => 'سلام $firstname$ 👋'));
	$g = ajax('getTemplate', array('id' => $tp['id']));
	check($g['body'] == 'سلام $firstname$ 👋', 'template saved + loaded (emoji ok)');
	ajax('deleteTemplate', array('id' => $tp['id']));
	$pv = ajax('preview', array('record' => $c1, 'body' => '$fullname$ / $university$ / $deeplink$'));
	check(strpos($pv['text'], 'تست‌یک TGTEST / ') === 0 && strpos($pv['text'], 'start=c' . $c1) !== false, 'preview merges fields + deep link', $pv['text']);

	echo "== views render\n";
	foreach (array(array('Index', array()), array('Index', array('tab' => 'messages', 'direction' => 'in')), array('Settings', array()),
		array('History', array('record' => $c1)), array('SendModal', array('record' => $c1)), array('SendModal', array('list' => json_encode(array('selected_ids' => array($c1)))))) as $v) {
		$cls = 'TelegramBot_' . $v[0] . '_View';
		require_once 'modules/TelegramBot/views/' . $v[0] . '.php';
		$p = $v[1] + array('module' => 'TelegramBot', 'view' => $v[0]);
		ob_start();
		try { $view = new $cls(); $view->checkPermission(req($p)); $view->process(req($p)); $html = ob_get_clean(); $e = ''; }
		catch (Throwable $ex) { $html = ob_get_clean(); $e = $ex->getMessage(); }
		$okHtml = $e === '' && strlen($html) > 200 && stripos($html, 'Fatal') === false && stripos($html, 'Smarty') === false;
		check($okHtml, 'view ' . $v[0] . ' ' . json_encode($v[1]) . ' renders', $e . ' ' . substr(strip_tags($html), 0, 200));
		if ($v[0] == 'History') { check(strpos($html, 'passport') !== false && strpos($html, 'action=Download') !== false, 'history shows incoming photo + caption'); }
	}
	// download action
	// (the action clears all output buffers, so run it in a separate PHP process and read its stdout)
	$script = tempnam(sys_get_temp_dir(), 'tgdl');
	file_put_contents($script, '<?php error_reporting(0); chdir("' . getcwd() . '"); require_once "vendor/autoload.php"; require_once "config.php"; require_once "include/utils/utils.php";
		vimport("includes.runtime.EntryPoint"); require_once "modules/Users/Users.php"; global $current_user; $current_user = Users::getActiveAdminUser();
		require_once "modules/TelegramBot/actions/Download.php"; $p = array("id" => ' . (int)$ph[0]['id'] . '); (new TelegramBot_Download_Action())->process(new Vtiger_Request($p, $p));');
	$bytes = shell_exec('php ' . escapeshellarg($script));
	unlink($script);
	check($bytes == 'MOCKFILE:photos/INPHOTO.jpg', 'download action serves the stored media');
} catch (Throwable $e) {
	check(false, 'exception', get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
	echo "== cleanup\n";
	$ids = array_filter($created['contacts']);
	if ($created['bot']) {
		foreach (TelegramBot_Store::rows("SELECT DISTINCT media_path FROM vtiger_telegrambot_messages WHERE botid=? AND media_path LIKE 'storage/TelegramBot/%'", array($created['bot'])) as $f) {
			$abs = TelegramBot_Store::absPath($f['media_path']); if ($abs) { @unlink($abs); }
		}
		$adb->pquery('DELETE FROM vtiger_telegrambot_messages WHERE botid=?', array($created['bot']));
		$adb->pquery('DELETE FROM vtiger_telegrambot_links WHERE botid=?', array($created['bot']));
		$adb->pquery('DELETE FROM vtiger_telegrambot_campaigns WHERE botid=? OR title LIKE ?', array($created['bot'], 'TGTEST%'));
		$adb->pquery('DELETE FROM vtiger_telegrambot_bots WHERE id=?', array($created['bot']));
		if (!empty($oldDefault)) { $adb->pquery('UPDATE vtiger_telegrambot_bots SET is_default=(id=?)', array($oldDefault['id'])); }
	}
	$adb->pquery("DELETE FROM vtiger_telegrambot_templates WHERE title LIKE 'TGTEST%'", array());
	if ($ids) {
		$q = generateQuestionMarks($ids);
		$adb->pquery("DELETE FROM vtiger_telegrambot_deeplinks WHERE contactid IN ($q)", $ids);
		foreach (array('vtiger_contactdetails' => 'contactid', 'vtiger_contactsubdetails' => 'contactsubscriptionid', 'vtiger_contactaddress' => 'contactaddressid',
			'vtiger_contactscf' => 'contactid', 'vtiger_customerdetails' => 'customerid', 'vtiger_freetagged_objects' => 'object_id', 'vtiger_crmentity' => 'crmid', 'vtiger_modtracker_basic' => 'crmid') as $tbl => $col) {
			$adb->pquery("DELETE FROM $tbl WHERE $col IN ($q)", $ids);
		}
	}
	foreach ($created['docs'] as $d) {
		$att = q1('SELECT attachmentsid FROM vtiger_seattachmentsrel WHERE crmid=?', array($d));
		foreach (array('vtiger_notes' => 'notesid', 'vtiger_notescf' => 'notesid', 'vtiger_seattachmentsrel' => 'crmid', 'vtiger_senotesrel' => 'notesid', 'vtiger_crmentity' => 'crmid', 'vtiger_modtracker_basic' => 'crmid') as $tbl => $col) {
			$adb->pquery("DELETE FROM $tbl WHERE $col=?", array($d));
		}
		if ($att) { $adb->pquery('DELETE FROM vtiger_attachments WHERE attachmentsid=?', array($att['attachmentsid'])); $adb->pquery('DELETE FROM vtiger_crmentity WHERE crmid=?', array($att['attachmentsid'])); }
	}
	@array_map('unlink', glob('storage/TGTEST/*')); @rmdir('storage/TGTEST');
	if ($created['tag']) { $adb->pquery('DELETE FROM vtiger_freetagged_objects WHERE tag_id=?', array($created['tag'])); $adb->pquery('DELETE FROM vtiger_freetags WHERE id=?', array($created['tag'])); }
	foreach (array('api_base', 'proxy', 'rate_per_sec', 'birthday_enabled', 'birthday_botid', 'birthday_last') as $k) { TelegramBot_Store::set($k, $saved[$k]); }
	echo "\n" . $pass . " passed, " . count($failures) . " failed" . ($failures ? ":\n  - " . implode("\n  - ", $failures) : '') . "\n";
	exit($failures ? 1 : 0);
}
