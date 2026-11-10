<?php
/*+**********************************************************************************
 * TelegramBot – AJAX endpoints (index.php?module=TelegramBot&action=Ajax&mode=...).
 * All modes answer with Vtiger_Response JSON ({success, result} / {success:false, error}).
 ************************************************************************************/
require_once 'modules/TelegramBot/lib/Access.php';

/** Gives access to Vtiger's "selected records of the list view" logic (selected/excluded ids, filter, search, tags). */
class TelegramBot_ListSelection extends Vtiger_Mass_Action {
	function ids(Vtiger_Request $r) { return $this->getRecordsListFromRequest($r); }
	function process(Vtiger_Request $r) {}
}

class TelegramBot_Ajax_Action extends Vtiger_Action_Controller {

	static $ADMIN_MODES = array('saveBot', 'deleteBot', 'testBot', 'setDefaultBot', 'toggleBot', 'saveSettings', 'testProxy', 'runBirthdays');
	static $MODES = array('send', 'count', 'picklistValues', 'searchDocuments', 'saveTemplate', 'deleteTemplate', 'getTemplate',
		'cancelCampaign', 'runWorker', 'setOptout', 'unlink', 'retryMessage', 'preview');

	function requiresPermission(Vtiger_Request $request) { return array(); }
	function checkPermission(Vtiger_Request $request) {
		$mode = $request->getMode();
		if (in_array($mode, self::$ADMIN_MODES)) { TelegramBot_Access::requireAdmin(); }
		else { TelegramBot_Access::requireUse(); }
		return true;
	}

	function process(Vtiger_Request $request) {
		$mode = $request->getMode();
		$response = new Vtiger_Response();
		try {
			if (!in_array($mode, self::$MODES) && !in_array($mode, self::$ADMIN_MODES)) { throw new Exception('Unknown mode'); }
			$response->setResult($this->$mode($request));
		} catch (Exception $e) {
			$response->setError($e->getCode(), $e->getMessage());
		}
		$response->emit();
	}

	protected function t($key) { return vtranslate($key, 'TelegramBot'); }
	protected function userId() { return (int)Users_Record_Model::getCurrentUserModel()->getId(); }
	protected function arr(Vtiger_Request $r, $key) {
		$v = $r->get($key);
		if ($v === '' || $v === null) { return array(); }
		return is_array($v) ? array_values($v) : explode(',', (string)$v);
	}

	/* ------------------------------------------------------------ targets */

	/** Returns array(type, value, label, ids|null). ids are resolved now for "ids" / "list" targets. */
	protected function target(Vtiger_Request $r) {
		$type = $r->get('target_type');
		switch ($type) {
			case 'ids':
				$ids = array_filter(array_map('intval', $this->arr($r, 'ids')));
				if (!$ids && $r->get('record')) { $ids = array((int)$r->get('record')); }
				$ids = array_values(array_filter($ids, array('TelegramBot_Access', 'canSeeContact')));
				$label = count($ids) == 1 ? $this->contactName($ids[0]) : count($ids) . ' ' . $this->t('LBL_CONTACTS');
				return array('ids', $ids, $label, $ids);
			case 'list':
				$p = json_decode((string)$r->getRaw('list'), true);
				if (!is_array($p)) { throw new Exception($this->t('LBL_NO_SELECTION')); }
				$p['module'] = 'Contacts';
				$ids = (new TelegramBot_ListSelection())->ids(new Vtiger_Request($p, $p));
				$ids = array_values(array_unique(array_map('intval', (array)$ids)));
				return array('ids', $ids, $this->t('LBL_LIST_SELECTION') . ' (' . count($ids) . ')', $ids);
			case 'tag':
				$v = array_map('intval', $this->arr($r, 'tags'));
				$names = array();
				foreach (TelegramBot_Service::tags() as $t) { if (in_array($t['id'], $v)) { $names[] = $t['tag']; } }
				return array('tag', $v, $this->t('LBL_TARGET_tag') . ': ' . implode('، ', $names), null);
			case 'group':
				$v = array_map('intval', $this->arr($r, 'groups'));
				$names = array();
				foreach (TelegramBot_Service::groups() as $g) { if (in_array($g['id'], $v)) { $names[] = $g['name']; } }
				return array('group', $v, $this->t('LBL_TARGET_group') . ': ' . implode('، ', $names), null);
			case 'filter':
				$v = array_map('intval', $this->arr($r, 'filters'));
				$names = array();
				foreach (TelegramBot_Service::filters() as $f) { if (in_array($f['id'], $v)) { $names[] = $f['name']; } }
				return array('filter', $v, $this->t('LBL_TARGET_filter') . ': ' . implode('، ', $names), null);
			case 'picklist':
				$field = (string)$r->get('pl_field');
				$fields = TelegramBot_Service::picklistFields();
				if (!isset($fields[$field])) { throw new Exception($this->t('LBL_CHOOSE_FIELD')); }
				$vals = $this->arr($r, 'pl_values');
				return array('picklist', array('field' => $field, 'values' => $vals), $fields[$field] . ': ' . implode('، ', $vals), null);
			case 'missing':
				$vals = $this->arr($r, 'missing');
				return array('missing', $vals, $this->t('LBL_TARGET_missing') . ($vals ? ': ' . implode('، ', $vals) : ''), null);
			case 'linked':
				return array('linked', array(), $this->t('LBL_TARGET_linked'), null);
		}
		throw new Exception($this->t('LBL_CHOOSE_TARGET'));
	}

	protected function contactName($id) {
		$d = TelegramBot_Service::contactsData(array($id));
		return isset($d[$id]) ? $d[$id]['fullname'] : '#' . $id;
	}

	function count(Vtiger_Request $r) {
		list($type, $value, $label, $ids) = $this->target($r);
		if ($ids === null) { $ids = TelegramBot_Service::resolveTargets($type, $value); }
		$bot = TelegramBot_Store::botOrDefault((int)$r->get('botid'));
		return array('total' => count($ids), 'reachable' => $bot ? TelegramBot_Service::countReachable($ids, $bot['id']) : 0, 'label' => $label);
	}

	function picklistValues(Vtiger_Request $r) { return TelegramBot_Service::picklistValues((string)$r->get('field')); }

	function searchDocuments(Vtiger_Request $r) {
		$q = '%' . trim((string)$r->get('q')) . '%';
		$rows = TelegramBot_Store::rows("SELECT n.notesid id, n.title, n.filename, n.filesize FROM vtiger_notes n
			INNER JOIN vtiger_crmentity e ON e.crmid=n.notesid AND e.deleted=0
			WHERE n.filelocationtype='I' AND n.filestatus=1 AND n.filename<>'' AND (n.title LIKE ? OR n.filename LIKE ?) ORDER BY e.modifiedtime DESC LIMIT 25", array($q, $q));
		$out = array();
		foreach ($rows as $row) {
			if (TelegramBot_Access::isAdmin() || Users_Privileges_Model::isPermitted('Documents', 'DetailView', $row['id'])) {
				$row['title'] = html_entity_decode($row['title'], ENT_QUOTES, 'UTF-8');
				$row['filename'] = html_entity_decode($row['filename'], ENT_QUOTES, 'UTF-8');
				$out[] = $row;
			}
		}
		return $out;
	}

	/* ------------------------------------------------------------ media */

	static function typeForMime($mime, $size, $name) {
		$ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
		if (in_array($mime, array('image/jpeg', 'image/png', 'image/webp')) && $size <= 10485760) { return 'photo'; }
		if ($mime == 'video/mp4' || $ext == 'mp4') { return 'video'; }
		if (in_array($mime, array('audio/mpeg', 'audio/mp4', 'audio/x-m4a', 'audio/mp3')) || in_array($ext, array('mp3', 'm4a'))) { return 'audio'; }
		return 'document';
	}

	/** Returns array(msg_type, media_path, media_name, media_mime). */
	protected function media(Vtiger_Request $r) {
		$source = $r->get('media_source');
		$wanted = $r->get('media_type');
		if ($source == 'upload') {
			if (empty($_FILES['media_file']) || $_FILES['media_file']['error'] != UPLOAD_ERR_OK) {
				$err = empty($_FILES['media_file']) ? 'no file' : 'upload error ' . $_FILES['media_file']['error'];
				throw new Exception($this->t('LBL_UPLOAD_FAILED') . ' (' . $err . ')');
			}
			$f = $_FILES['media_file'];
			if ($f['size'] > 52428800) { throw new Exception($this->t('LBL_FILE_TOO_BIG')); }
			$mime = function_exists('mime_content_type') ? mime_content_type($f['tmp_name']) : $f['type'];
			$name = TelegramBot_Store::safeName($f['name']);
			$path = TelegramBot_Store::storeFile($f['tmp_name'], $name, 'out', true);
			$type = isset(TelegramBot_Service::$MEDIA[$wanted]) ? $wanted : self::typeForMime($mime, $f['size'], $name);
			return array($type, $path, $name, $mime);
		}
		if ($source == 'document') {
			$docid = (int)$r->get('docid');
			if (!$docid || !(TelegramBot_Access::isAdmin() || Users_Privileges_Model::isPermitted('Documents', 'DetailView', $docid))) { throw new Exception($this->t('LBL_CHOOSE_DOCUMENT')); }
			$doc = TelegramBot_Store::documentFile($docid);
			if (!$doc) { throw new Exception($this->t('LBL_DOCUMENT_NO_FILE')); }
			$size = filesize(TelegramBot_Store::absPath($doc['path']));
			if ($size > 52428800) { throw new Exception($this->t('LBL_FILE_TOO_BIG')); }
			$type = isset(TelegramBot_Service::$MEDIA[$wanted]) ? $wanted : self::typeForMime($doc['mime'], $size, $doc['name']);
			return array($type, $doc['path'], $doc['name'], $doc['mime']);
		}
		return array('text', '', '', '');
	}

	/* ------------------------------------------------------------ send */

	function preview(Vtiger_Request $r) {
		$id = (int)$r->get('record');
		$data = array();
		if ($id && TelegramBot_Access::canSeeContact($id)) { $d = TelegramBot_Service::contactsData(array($id)); $data = isset($d[$id]) ? $d[$id] : array(); }
		$bot = TelegramBot_Store::botOrDefault((int)$r->get('botid'));
		return array('text' => TelegramBot_Service::merge((string)$r->getRaw('body'), $data, array('deeplink' => $id ? TelegramBot_Store::deepLink($bot, $id) : '')));
	}

	function send(Vtiger_Request $r) {
		$bot = TelegramBot_Store::botOrDefault((int)$r->get('botid'));
		if (!$bot) { throw new Exception($this->t('LBL_NO_BOT')); }
		$body = trim(str_replace("\r\n", "\n", (string)$r->getRaw('body')));
		list($type, $value, $label, $ids) = $this->target($r);
		if ($ids !== null && !$ids) { throw new Exception($this->t('LBL_NO_RECIPIENTS')); }
		$when = time();
		if ($r->get('when') == 'later') {
			$when = TelegramBot_Store::parseUserTime((string)$r->get('send_date'), (string)$r->get('send_time'));
			if (!$when) { throw new Exception($this->t('LBL_BAD_DATE')); }
			if ($when < time() - 300) { throw new Exception($this->t('LBL_DATE_PAST')); }
		}
		list($msgType, $path, $name, $mime) = $this->media($r);
		if ($body === '' && $msgType == 'text') { throw new Exception($this->t('LBL_EMPTY_MESSAGE')); }
		if ($msgType != 'text' && mb_strlen($body) > TelegramBot_Service::CAPTION_MAX) {
			// Telegram captions are limited; Service::deliver sends the text as a separate message.
		}

		// one contact, now: send immediately and report the result
		if ($ids !== null && count($ids) == 1 && $r->get('when') != 'later') {
			$cid = $ids[0];
			$link = TelegramBot_Store::link($bot['id'], $cid);
			if (!$link) { throw new Exception($this->t('LBL_NOT_LINKED') . ' ' . TelegramBot_Store::deepLink($bot, $cid)); }
			if ($link['optout']) { throw new Exception($this->t('LBL_OPTED_OUT')); }
			$data = TelegramBot_Service::contactsData(array($cid));
			$text = TelegramBot_Service::merge($body, isset($data[$cid]) ? $data[$cid] : array(), array('deeplink' => TelegramBot_Store::deepLink($bot, $cid)));
			$mid = TelegramBot_Store::addMessage(array('botid' => $bot['id'], 'contactid' => $cid, 'chat_id' => $link['chat_id'], 'msg_type' => $msgType,
				'body' => $text, 'media_path' => $path, 'media_name' => $name, 'media_mime' => $mime, 'userid' => $this->userId()));
			$row = TelegramBot_Service::sendRow(TelegramBot_Store::message($mid), $bot);
			return array('single' => true, 'status' => $row['status'], 'error' => $row['error'], 'message' => $this->t('LBL_STATUS_' . $row['status']));
		}

		$cid = TelegramBot_Service::createCampaign(array('title' => (string)$r->get('title'), 'botid' => $bot['id'], 'msg_type' => $msgType, 'body' => $body,
			'media_path' => $path, 'media_name' => $name, 'media_mime' => $mime, 'target_type' => $type, 'target_value' => $value,
			'target_label' => mb_substr($label, 0, 500), 'scheduled_at' => $when, 'userid' => $this->userId()));
		$out = array('campaign' => $cid, 'scheduled' => TelegramBot_Store::fmtTime($when), 'later' => $r->get('when') == 'later');
		if (!$out['later']) {
			$out['worker'] = TelegramBot_Service::runWorker(array('seconds' => 15, 'poll' => false));
		}
		$c = TelegramBot_Store::row('SELECT total, skipped, status FROM vtiger_telegrambot_campaigns WHERE id=?', array($cid));
		$out += $c;
		$st = TelegramBot_Service::campaignStats(array($cid));
		$out['stats'] = isset($st[$cid]) ? $st[$cid] : array();
		return $out;
	}

	function cancelCampaign(Vtiger_Request $r) {
		$id = (int)$r->get('id');
		$c = TelegramBot_Store::row('SELECT userid FROM vtiger_telegrambot_campaigns WHERE id=?', array($id));
		if (!$c || (!TelegramBot_Access::isAdmin() && $c['userid'] != $this->userId())) { TelegramBot_Access::deny(); }
		TelegramBot_Service::cancelCampaign($id);
		return true;
	}

	function retryMessage(Vtiger_Request $r) {
		$m = TelegramBot_Store::message((int)$r->get('id'));
		if (!$m || $m['direction'] != 'out' || !in_array($m['status'], array('failed', 'cancelled', 'skipped'))) { throw new Exception('Not retryable'); }
		if ($m['contactid'] && !TelegramBot_Access::canSeeContact($m['contactid'])) { TelegramBot_Access::deny(); }
		TelegramBot_Store::update('vtiger_telegrambot_messages', $m['id'], array('status' => 'queued', 'attempts' => 0, 'next_try' => 0, 'error' => ''));
		$row = TelegramBot_Service::sendRow(TelegramBot_Store::message($m['id']));
		return array('status' => $row['status'], 'error' => $row['error']);
	}

	function runWorker(Vtiger_Request $r) {
		return TelegramBot_Service::runWorker(array('seconds' => 20, 'poll' => true, 'longpoll' => false));
	}
	function runBirthdays(Vtiger_Request $r) {
		return array('queued' => TelegramBot_Service::runBirthdays(true));
	}

	/* ------------------------------------------------------------ links */

	function setOptout(Vtiger_Request $r) {
		$cid = (int)$r->get('record');
		if (!TelegramBot_Access::canSeeContact($cid)) { TelegramBot_Access::deny(); }
		TelegramBot_Store::db()->pquery('UPDATE vtiger_telegrambot_links SET optout=? WHERE contactid=? AND botid=?', array($r->get('value') ? 1 : 0, $cid, (int)$r->get('botid')));
		return true;
	}
	function unlink(Vtiger_Request $r) {
		$cid = (int)$r->get('record');
		if (!TelegramBot_Access::canSeeContact($cid)) { TelegramBot_Access::deny(); }
		TelegramBot_Store::db()->pquery('DELETE FROM vtiger_telegrambot_links WHERE contactid=? AND botid=?', array($cid, (int)$r->get('botid')));
		return true;
	}

	/* ------------------------------------------------------------ templates */

	function getTemplate(Vtiger_Request $r) { return TelegramBot_Store::row('SELECT * FROM vtiger_telegrambot_templates WHERE id=?', array((int)$r->get('id'))); }
	function saveTemplate(Vtiger_Request $r) {
		$title = trim((string)$r->get('title'));
		$body = trim(str_replace("\r\n", "\n", (string)$r->getRaw('body')));
		if ($title === '' || $body === '') { throw new Exception($this->t('LBL_TEMPLATE_REQUIRED')); }
		$id = (int)$r->get('id');
		if ($id) { TelegramBot_Store::update('vtiger_telegrambot_templates', $id, array('title' => $title, 'body' => $body)); }
		else { $id = TelegramBot_Store::insert('vtiger_telegrambot_templates', array('title' => $title, 'body' => $body, 'userid' => $this->userId(), 'created' => time())); }
		return array('id' => $id);
	}
	function deleteTemplate(Vtiger_Request $r) {
		TelegramBot_Store::db()->pquery('DELETE FROM vtiger_telegrambot_templates WHERE id=?', array((int)$r->get('id')));
		return true;
	}

	/* ------------------------------------------------------------ bots & settings (admin) */

	function saveBot(Vtiger_Request $r) {
		$id = (int)$r->get('id');
		$token = trim((string)$r->getRaw('token'));
		$title = trim((string)$r->get('title'));
		if (!$id && !preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) { throw new Exception($this->t('LBL_BAD_TOKEN')); }
		$data = array('title' => $title);
		if ($token !== '') {
			if (!preg_match('/^\d+:[A-Za-z0-9_-]{20,}$/', $token)) { throw new Exception($this->t('LBL_BAD_TOKEN')); }
			$data['token'] = $token;
			$me = (new TelegramBot_Api($token))->call('getMe', array(), array(), 20);
			if (!empty($me['ok'])) {
				$data['username'] = $me['result']['username'];
				$data['tg_botid'] = $me['result']['id'];
				if ($title === '') { $data['title'] = $me['result']['first_name']; }
				$data['last_error'] = '';
			} else {
				$data['last_error'] = 'getMe: ' . (isset($me['description']) ? $me['description'] : 'error');
				if ($data['title'] === '') { $data['title'] = 'Bot ' . strtok($token, ':'); }
			}
		}
		if ($id) {
			TelegramBot_Store::update('vtiger_telegrambot_bots', $id, $data);
		} else {
			$data += array('created' => time(), 'active' => 1, 'is_default' => TelegramBot_Store::defaultBot() ? 0 : 1);
			$id = TelegramBot_Store::insert('vtiger_telegrambot_bots', $data);
		}
		$bot = TelegramBot_Store::bot($id);
		return array('id' => $id, 'username' => $bot['username'], 'error' => $bot['last_error']);
	}
	function deleteBot(Vtiger_Request $r) {
		$id = (int)$r->get('id');
		TelegramBot_Store::db()->pquery('DELETE FROM vtiger_telegrambot_bots WHERE id=?', array($id));
		TelegramBot_Store::db()->pquery("UPDATE vtiger_telegrambot_messages SET status='cancelled', error='bot deleted' WHERE botid=? AND status='queued'", array($id));
		return true;
	}
	function setDefaultBot(Vtiger_Request $r) {
		TelegramBot_Store::db()->pquery('UPDATE vtiger_telegrambot_bots SET is_default=(id=?)', array((int)$r->get('id')));
		return true;
	}
	function toggleBot(Vtiger_Request $r) {
		TelegramBot_Store::db()->pquery('UPDATE vtiger_telegrambot_bots SET active=? WHERE id=?', array($r->get('value') ? 1 : 0, (int)$r->get('id')));
		return true;
	}
	function testBot(Vtiger_Request $r) {
		$bot = TelegramBot_Store::bot((int)$r->get('id'));
		if (!$bot) { throw new Exception('Bot not found'); }
		$api = TelegramBot_Api::forBot($bot);
		$me = $api->call('getMe', array(), array(), 20);
		if (empty($me['ok'])) {
			$err = isset($me['description']) ? $me['description'] : 'error';
			TelegramBot_Store::update('vtiger_telegrambot_bots', $bot['id'], array('last_error' => 'getMe: ' . $err));
			throw new Exception($err);
		}
		$wh = $api->call('getWebhookInfo', array(), array(), 20);
		if (!empty($wh['result']['url'])) { $api->call('deleteWebhook', array()); }   // getUpdates does not work while a webhook is set
		TelegramBot_Store::update('vtiger_telegrambot_bots', $bot['id'], array('username' => $me['result']['username'], 'tg_botid' => $me['result']['id'], 'last_error' => ''));
		return array('username' => $me['result']['username'], 'name' => $me['result']['first_name'], 'webhook_removed' => !empty($wh['result']['url']));
	}
	function testProxy(Vtiger_Request $r) {
		$api = new TelegramBot_Api('0:test', trim((string)$r->get('api_base')), trim((string)$r->getRaw('proxy')));
		$res = $api->call('getMe', array(), array(), 20);
		// a reachable API answers 401/404 for the dummy token; error_code 0 means no connection
		$reach = isset($res['error_code']) && $res['error_code'] > 0 || !empty($res['ok']);
		return array('reachable' => $reach, 'detail' => isset($res['description']) ? $res['description'] : '');
	}
	function saveSettings(Vtiger_Request $r) {
		foreach (TelegramBot_Store::$DEFAULTS as $k => $def) {
			if (in_array($k, array('birthday_last', 'worker_last_run', 'worker_last_result'))) { continue; }
			if (!$r->has($k)) { if ($k == 'birthday_enabled') { TelegramBot_Store::set($k, '0'); } continue; }
			$v = trim(str_replace("\r\n", "\n", (string)$r->getRaw($k)));
			if ($k == 'api_base' && $v !== '' && !preg_match('#^https?://#i', $v)) { throw new Exception('API base must start with http(s)://'); }
			if ($k == 'proxy' && $v !== '' && !preg_match('#^(https?|socks4a?|socks5h?)://#i', $v)) { throw new Exception($this->t('LBL_BAD_PROXY')); }
			if (in_array($k, array('rate_per_sec', 'max_attempts', 'worker_seconds', 'birthday_hour', 'birthday_botid'))) { $v = (string)(int)$v; }
			if ($k == 'rate_per_sec') { $v = (string)max(1, min(30, (int)$v)); }
			if ($k == 'worker_seconds') { $v = (string)max(10, min(55, (int)$v)); }
			if ($k == 'timezone') { try { new DateTimeZone($v); } catch (Exception $e) { $v = 'Asia/Tehran'; } }
			TelegramBot_Store::set($k, $v === '' && $k == 'api_base' ? $def : $v);
		}
		return true;
	}
}
