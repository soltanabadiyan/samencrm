<?php
/*+**********************************************************************************
 * TelegramBot – business logic: merge fields, targets, campaigns, the send queue,
 * incoming updates (/start linking, phone linking, /stop opt-out, history) and the cron worker.
 ************************************************************************************/
require_once 'modules/TelegramBot/lib/Store.php';
require_once 'modules/TelegramBot/lib/Api.php';

class TelegramBot_Service {

	const TEXT_MAX = 4096;
	const CAPTION_MAX = 1024;
	const DOWNLOAD_MAX = 20971520;   // Bot API getFile limit (20 MB)

	static $MEDIA = array(
		'photo' => array('sendPhoto', 'photo'),
		'document' => array('sendDocument', 'document'),
		'video' => array('sendVideo', 'video'),
		'audio' => array('sendAudio', 'audio'),
	);

	/** $alias$ => Contacts field name */
	static $ALIASES = array(
		'major' => 'cf_ss_major', 'university' => 'cf_ss_university', 'level' => 'cf_ss_level',
		'passport' => 'cf_ss_passport', 'nationality' => 'cf_ss_nationality', 'faculty' => 'cf_ss_faculty',
		'student_no' => 'cf_ss_student_no', 'tracking_code' => 'cf_ss_tracking_code', 'phone' => 'mobile',
		'missing_info' => 'cf_ss_missing_info', 'name' => 'firstname',
	);

	/* ================================================================== merge fields */

	static function contactFields() {
		static $f = null;
		if ($f === null) {
			$f = array();
			$rows = TelegramBot_Store::rows("SELECT fieldname, columnname, tablename, fieldlabel, uitype FROM vtiger_field
				WHERE tabid=? AND presence IN (0,2) AND tablename IN ('vtiger_contactdetails','vtiger_contactsubdetails','vtiger_contactscf','vtiger_contactaddress')
				ORDER BY block, sequence", array(getTabid('Contacts')));
			foreach ($rows as $r) { $f[$r['fieldname']] = $r; }
		}
		return $f;
	}

	/** Raw field values of contacts, keyed by contact id (fieldname => value). */
	static function contactsData($ids) {
		$ids = array_values(array_filter(array_map('intval', (array)$ids)));
		if (!$ids) { return array(); }
		$cols = array();
		foreach (self::contactFields() as $name => $f) { $cols[] = $f['tablename'] . '.' . $f['columnname'] . ' AS `' . $name . '`'; }
		$out = array();
		foreach (array_chunk($ids, 500) as $chunk) {
			$rows = TelegramBot_Store::rows('SELECT vtiger_contactdetails.contactid AS __id, ' . implode(',', $cols) . '
				FROM vtiger_contactdetails
				INNER JOIN vtiger_crmentity ON vtiger_crmentity.crmid=vtiger_contactdetails.contactid AND vtiger_crmentity.deleted=0
				LEFT JOIN vtiger_contactsubdetails ON vtiger_contactsubdetails.contactsubscriptionid=vtiger_contactdetails.contactid
				LEFT JOIN vtiger_contactaddress ON vtiger_contactaddress.contactaddressid=vtiger_contactdetails.contactid
				LEFT JOIN vtiger_contactscf ON vtiger_contactscf.contactid=vtiger_contactdetails.contactid
				WHERE vtiger_contactdetails.contactid IN (' . generateQuestionMarks($chunk) . ')', $chunk);
			foreach ($rows as $r) {
				$id = (int)$r['__id']; unset($r['__id']);
				foreach ($r as $k => $v) { $r[$k] = html_entity_decode((string)$v, ENT_QUOTES, 'UTF-8'); }
				$r['fullname'] = trim($r['firstname'] . ' ' . $r['lastname']);
				$out[$id] = $r;
			}
		}
		return $out;
	}

	/** Replace $firstname$, $major$, $contacts-cf_ss_major$, $deeplink$ ... */
	static function merge($text, $data, $extra = array()) {
		return preg_replace_callback('/\$([A-Za-z0-9_\-]+)\$/', function ($m) use ($data, $extra) {
			$k = strtolower($m[1]);
			if (strpos($k, 'contacts-') === 0) { $k = substr($k, 9); }
			if (isset($extra[$k])) { return $extra[$k]; }
			if (isset(TelegramBot_Service::$ALIASES[$k])) { $k = TelegramBot_Service::$ALIASES[$k]; }
			if (array_key_exists($k, $data)) { return str_replace(' |##| ', '، ', (string)$data[$k]); }
			return $m[0];
		}, (string)$text);
	}

	/** Merge fields for the UI: token => label */
	static function mergeFieldList() {
		$out = array('$firstname$' => vtranslate('First Name', 'Contacts'), '$lastname$' => vtranslate('Last Name', 'Contacts'),
			'$fullname$' => vtranslate('LBL_FULLNAME', 'TelegramBot'));
		foreach (self::$ALIASES as $a => $field) {
			$fs = self::contactFields();
			if (isset($fs[$field]) && $a !== 'name') { $out['$' . $a . '$'] = vtranslate($fs[$field]['fieldlabel'], 'Contacts'); }
		}
		foreach (self::contactFields() as $name => $f) {
			if (in_array($name, array('firstname', 'lastname')) || in_array($name, self::$ALIASES)) { continue; }
			if (in_array($f['uitype'], array(1, 2, 5, 11, 13, 15, 16, 7, 9))) { $out['$' . $name . '$'] = vtranslate($f['fieldlabel'], 'Contacts'); }
		}
		$out['$deeplink$'] = vtranslate('LBL_DEEPLINK', 'TelegramBot');
		return $out;
	}

	/* ================================================================== targets */

	/** Contacts picklist fields that can be used as bulk targets. */
	static function picklistFields() {
		$out = array();
		foreach (self::contactFields() as $name => $f) {
			if (in_array($f['uitype'], array(15, 16, 33))) { $out[$name] = vtranslate($f['fieldlabel'], 'Contacts'); }
		}
		return $out;
	}
	static function picklistValues($field) {
		$fs = self::contactFields();
		if (!isset($fs[$field]) || !in_array($fs[$field]['uitype'], array(15, 16, 33))) { return array(); }
		$f = $fs[$field];
		$rows = TelegramBot_Store::rows('SELECT ' . $f['tablename'] . '.' . $f['columnname'] . ' v, COUNT(*) n FROM vtiger_contactdetails
			INNER JOIN vtiger_crmentity e ON e.crmid=vtiger_contactdetails.contactid AND e.deleted=0
			LEFT JOIN vtiger_contactscf ON vtiger_contactscf.contactid=vtiger_contactdetails.contactid
			LEFT JOIN vtiger_contactsubdetails ON vtiger_contactsubdetails.contactsubscriptionid=vtiger_contactdetails.contactid
			GROUP BY 1 HAVING v IS NOT NULL AND v<>"" ORDER BY n DESC LIMIT 500');
		$out = array();
		foreach ($rows as $r) { $out[] = array('value' => html_entity_decode($r['v'], ENT_QUOTES, 'UTF-8'), 'count' => (int)$r['n']); }
		return $out;
	}

	static function tags() {
		return TelegramBot_Store::rows("SELECT t.id, t.tag, COUNT(o.object_id) n FROM vtiger_freetags t
			INNER JOIN vtiger_freetagged_objects o ON o.tag_id=t.id AND o.module='Contacts'
			INNER JOIN vtiger_crmentity e ON e.crmid=o.object_id AND e.deleted=0 GROUP BY t.id, t.tag ORDER BY t.tag");
	}
	static function groups() {
		return TelegramBot_Store::rows("SELECT g.groupid id, g.groupname name, COUNT(e.crmid) n FROM vtiger_groups g
			LEFT JOIN vtiger_crmentity e ON e.smownerid=g.groupid AND e.setype='Contacts' AND e.deleted=0 GROUP BY g.groupid, g.groupname ORDER BY g.groupname");
	}
	static function filters() {
		$out = array();
		$rows = TelegramBot_Store::rows("SELECT cvid, viewname, userid, status FROM vtiger_customview WHERE entitytype='Contacts' ORDER BY viewname");
		$user = Users_Record_Model::getCurrentUserModel();
		foreach ($rows as $r) {
			// 0/1 default/private (own), 3 public, 2 pending
			if (!$user->isAdminUser() && $r['userid'] != $user->getId() && !in_array($r['status'], array(0, 3))) { continue; }
			$out[] = array('id' => $r['cvid'], 'name' => vtranslate($r['viewname'], 'Contacts'));
		}
		return $out;
	}

	/** Contact ids for a target. $value is an array (decoded JSON). */
	static function resolveTargets($type, $value) {
		$ids = array();
		switch ($type) {
			case 'ids':
				$ids = array_map('intval', (array)$value);
				break;
			case 'tag':
				$tags = array_map('intval', (array)$value);
				if ($tags) {
					foreach (TelegramBot_Store::rows("SELECT DISTINCT object_id FROM vtiger_freetagged_objects WHERE module='Contacts' AND tag_id IN (" . generateQuestionMarks($tags) . ')', $tags) as $r) { $ids[] = (int)$r['object_id']; }
				}
				break;
			case 'group':
				$g = array_map('intval', (array)$value);
				if ($g) {
					foreach (TelegramBot_Store::rows("SELECT crmid FROM vtiger_crmentity WHERE setype='Contacts' AND deleted=0 AND smownerid IN (" . generateQuestionMarks($g) . ')', $g) as $r) { $ids[] = (int)$r['crmid']; }
				}
				break;
			case 'filter':
				foreach ((array)$value as $cvid) {
					$cv = CustomView_Record_Model::getInstanceById((int)$cvid);
					if ($cv && $cv->get('entitytype') == 'Contacts') { $ids = array_merge($ids, array_map('intval', $cv->getRecordIds(false, 'Contacts'))); }
				}
				break;
			case 'picklist':
				$fs = self::contactFields();
				$field = isset($value['field']) ? $value['field'] : '';
				$vals = isset($value['values']) ? array_values((array)$value['values']) : array();
				if (isset($fs[$field]) && $vals) {
					$f = $fs[$field];
					$col = $f['tablename'] . '.' . $f['columnname'];
					$enc = array_map(function ($v) { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }, $vals);
					$params = array_merge($vals, $enc);
					$rows = TelegramBot_Store::rows('SELECT vtiger_contactdetails.contactid FROM vtiger_contactdetails
						INNER JOIN vtiger_crmentity e ON e.crmid=vtiger_contactdetails.contactid AND e.deleted=0
						LEFT JOIN vtiger_contactscf ON vtiger_contactscf.contactid=vtiger_contactdetails.contactid
						LEFT JOIN vtiger_contactsubdetails ON vtiger_contactsubdetails.contactsubscriptionid=vtiger_contactdetails.contactid
						WHERE ' . $col . ' IN (' . generateQuestionMarks($params) . ')', $params);
					foreach ($rows as $r) { $ids[] = (int)$r['contactid']; }
				}
				break;
			case 'missing':
				// contacts whose "missing info" field mentions any of the given items (or is not empty at all)
				$fs = self::contactFields();
				if (isset($fs['cf_ss_missing_info'])) {
					$items = array_values(array_filter((array)$value));
					$where = "vtiger_contactscf.cf_ss_missing_info IS NOT NULL AND vtiger_contactscf.cf_ss_missing_info<>''";
					$params = array();
					if ($items) {
						$or = array();
						foreach ($items as $it) { $or[] = 'vtiger_contactscf.cf_ss_missing_info LIKE ?'; $params[] = '%' . $it . '%'; }
						$where .= ' AND (' . implode(' OR ', $or) . ')';
					}
					$rows = TelegramBot_Store::rows('SELECT vtiger_contactscf.contactid FROM vtiger_contactscf
						INNER JOIN vtiger_crmentity e ON e.crmid=vtiger_contactscf.contactid AND e.deleted=0 WHERE ' . $where, $params);
					foreach ($rows as $r) { $ids[] = (int)$r['contactid']; }
				}
				break;
			case 'linked':
				foreach (TelegramBot_Store::rows('SELECT DISTINCT contactid FROM vtiger_telegrambot_links WHERE optout=0 AND blocked=0') as $r) { $ids[] = (int)$r['contactid']; }
				break;
		}
		return array_values(array_unique(array_filter($ids)));
	}

	/** Split the "missing info" values into distinct items (for the UI). */
	static function missingItems() {
		$fs = self::contactFields();
		if (!isset($fs['cf_ss_missing_info'])) { return array(); }
		$count = array();
		foreach (TelegramBot_Store::rows("SELECT cf_ss_missing_info v FROM vtiger_contactscf c INNER JOIN vtiger_crmentity e ON e.crmid=c.contactid AND e.deleted=0
			WHERE cf_ss_missing_info IS NOT NULL AND cf_ss_missing_info<>''") as $r) {
			foreach (preg_split('/\s*(?:\|##\||[,،;\n])\s*/u', html_entity_decode($r['v'], ENT_QUOTES, 'UTF-8')) as $it) {
				$it = trim($it); if ($it === '') { continue; }
				$count[$it] = (isset($count[$it]) ? $count[$it] : 0) + 1;
			}
		}
		arsort($count);
		$out = array();
		foreach (array_slice($count, 0, 60, true) as $k => $n) { $out[] = array('value' => $k, 'count' => $n); }
		return $out;
	}

	/** How many of these contacts can receive messages from the bot. */
	static function countReachable($ids, $botid) {
		$ids = array_values(array_filter(array_map('intval', $ids)));
		$n = 0;
		foreach (array_chunk($ids, 1000) as $chunk) {
			$params = array_merge(array($botid), $chunk);
			$r = TelegramBot_Store::row('SELECT COUNT(DISTINCT chat_id) n FROM vtiger_telegrambot_links WHERE botid=? AND optout=0 AND blocked=0 AND contactid IN (' . generateQuestionMarks($chunk) . ')', $params);
			$n += (int)$r['n'];
		}
		return $n;
	}

	/* ================================================================== campaigns */

	static function createCampaign($c) {
		$c += array('kind' => 'manual', 'msg_type' => 'text', 'status' => 'scheduled', 'created' => time(), 'scheduled_at' => time(),
			'target_type' => 'ids', 'target_value' => array(), 'target_label' => '', 'title' => '', 'body' => '', 'userid' => 0);
		if (is_array($c['target_value'])) { $c['target_value'] = json_encode($c['target_value'], JSON_UNESCAPED_UNICODE); }
		if ($c['title'] === '') { $c['title'] = mb_substr(preg_replace('/\s+/u', ' ', $c['body'] !== '' ? $c['body'] : $c['media_name']), 0, 60); }
		return TelegramBot_Store::insert('vtiger_telegrambot_campaigns', $c);
	}

	/** Turn a due campaign into queued messages (one per reachable chat). */
	static function expandCampaign($c) {
		global $current_user;
		TelegramBot_Store::update('vtiger_telegrambot_campaigns', $c['id'], array('status' => 'expanding'));
		$savedUser = $current_user;
		try {
			if ($c['userid']) {   // resolve filters with the permissions of the user who created the campaign
				$u = CRMEntity::getInstance('Users');
				$u->retrieveCurrentUserInfoFromFile($c['userid']);
				if ($u->id) { $current_user = $u; }
			}
			$bot = TelegramBot_Store::botOrDefault($c['botid']);
			if (!$bot) { throw new Exception('No active bot'); }
			$ids = self::resolveTargets($c['target_type'], json_decode($c['target_value'], true));
			if ($current_user && !is_admin($current_user) && $c['target_type'] != 'ids') {   // ids were checked when the campaign was created
				$ids = array_values(array_filter($ids, function ($id) { return isPermitted('Contacts', 'DetailView', $id) == 'yes'; }));
			}
			$data = self::contactsData($ids);
			$links = array();
			foreach (array_chunk(array_keys($data), 1000) as $chunk) {
				$params = array_merge(array($bot['id']), $chunk);
				foreach (TelegramBot_Store::rows('SELECT contactid, chat_id FROM vtiger_telegrambot_links WHERE botid=? AND optout=0 AND blocked=0 AND contactid IN (' . generateQuestionMarks($chunk) . ')', $params) as $l) {
					$links[(int)$l['contactid']] = $l['chat_id'];
				}
			}
			$seen = array(); $total = 0;
			foreach ($data as $cid => $row) {
				if (!isset($links[$cid]) || isset($seen[$links[$cid]])) { continue; }
				$seen[$links[$cid]] = 1;
				$body = self::merge($c['body'], $row, array('deeplink' => TelegramBot_Store::deepLink($bot, $cid)));
				TelegramBot_Store::addMessage(array('botid' => $bot['id'], 'contactid' => $cid, 'chat_id' => $links[$cid], 'msg_type' => $c['msg_type'],
					'body' => $body, 'media_path' => $c['media_path'], 'media_name' => $c['media_name'], 'media_mime' => $c['media_mime'],
					'campaignid' => $c['id'], 'userid' => $c['userid'], 'status' => 'queued'));
				$total++;
			}
			TelegramBot_Store::update('vtiger_telegrambot_campaigns', $c['id'], array('botid' => $bot['id'], 'status' => $total ? 'queued' : 'done',
				'total' => $total, 'skipped' => count($ids) - $total, 'processed_at' => time()));
			return $total;
		} catch (Exception $e) {
			TelegramBot_Store::update('vtiger_telegrambot_campaigns', $c['id'], array('status' => 'failed', 'error' => $e->getMessage(), 'processed_at' => time()));
			return 0;
		} finally {
			$current_user = $savedUser;
		}
	}

	static function cancelCampaign($id) {
		TelegramBot_Store::db()->pquery("UPDATE vtiger_telegrambot_messages SET status='cancelled' WHERE campaignid=? AND status='queued'", array($id));
		TelegramBot_Store::db()->pquery("UPDATE vtiger_telegrambot_campaigns SET status='cancelled' WHERE id=? AND status IN ('scheduled','queued')", array($id));
	}

	static function campaignStats($ids) {
		$out = array();
		$ids = array_values(array_filter(array_map('intval', $ids)));
		if (!$ids) { return $out; }
		foreach (TelegramBot_Store::rows('SELECT campaignid, status, COUNT(*) n FROM vtiger_telegrambot_messages WHERE campaignid IN (' . generateQuestionMarks($ids) . ') GROUP BY campaignid, status', $ids) as $r) {
			$out[$r['campaignid']][$r['status']] = (int)$r['n'];
		}
		return $out;
	}

	static function finishCampaigns() {
		TelegramBot_Store::db()->pquery("UPDATE vtiger_telegrambot_campaigns c SET status='done' WHERE status='queued'
			AND NOT EXISTS (SELECT 1 FROM vtiger_telegrambot_messages m WHERE m.campaignid=c.id AND m.status IN ('queued','sending'))", array());
	}

	/* ================================================================== sending */

	static function textChunks($text) {
		$out = array();
		$text = (string)$text;
		while (mb_strlen($text) > self::TEXT_MAX) {
			$part = mb_substr($text, 0, self::TEXT_MAX);
			$cut = mb_strrpos($part, "\n");
			if ($cut === false || $cut < self::TEXT_MAX / 2) { $cut = mb_strrpos($part, ' '); }
			if ($cut === false || $cut < self::TEXT_MAX / 2) { $cut = self::TEXT_MAX; }
			$out[] = mb_substr($text, 0, $cut);
			$text = ltrim(mb_substr($text, $cut));
		}
		if ($text !== '') { $out[] = $text; }
		return $out;
	}

	static function fileIdFrom($type, $result) {
		if ($type == 'photo' && !empty($result['photo'])) { $p = end($result['photo']); return $p['file_id']; }
		foreach (array('document', 'video', 'audio', 'animation', 'voice') as $k) {
			if (!empty($result[$k]['file_id'])) { return $result[$k]['file_id']; }
		}
		return '';
	}

	/**
	 * Send one message row through the Bot API. Returns the last API reply.
	 * Media is uploaded once per campaign; later recipients reuse Telegram's file_id.
	 */
	static function deliver($msg, $bot, &$fileIdOut = null) {
		$api = TelegramBot_Api::forBot($bot);
		$chat = $msg['chat_id'];
		$body = (string)$msg['body'];
		$res = null;
		if ($msg['msg_type'] == 'text' || !isset(self::$MEDIA[$msg['msg_type']])) {
			foreach (self::textChunks($body) as $chunk) {
				$res = $api->call('sendMessage', array('chat_id' => $chat, 'text' => $chunk, 'disable_web_page_preview' => false));
				if (empty($res['ok'])) { return $res; }
			}
			return $res ? $res : array('ok' => false, 'error_code' => 400, 'description' => 'Empty message');
		}
		list($method, $param) = self::$MEDIA[$msg['msg_type']];
		$params = array('chat_id' => $chat);
		$caption = mb_strlen($body) <= self::CAPTION_MAX;
		if ($caption && $body !== '') { $params['caption'] = $body; }
		$files = array();
		$fileId = $msg['tg_file_id'];
		if ($fileId === '' && $msg['campaignid']) {
			$c = TelegramBot_Store::row('SELECT tg_file_id FROM vtiger_telegrambot_campaigns WHERE id=? AND botid=?', array($msg['campaignid'], $bot['id']));
			if ($c && $c['tg_file_id'] !== '') { $fileId = $c['tg_file_id']; }
		}
		if ($fileId !== '') {
			$params[$param] = $fileId;
		} else {
			$abs = TelegramBot_Store::absPath($msg['media_path']);
			if (!$abs) { return array('ok' => false, 'error_code' => 400, 'description' => 'Media file not found: ' . $msg['media_name']); }
			$files[$param] = array('path' => $abs, 'name' => $msg['media_name'] ? $msg['media_name'] : basename($abs), 'mime' => $msg['media_mime']);
		}
		$res = $api->call($method, $params, $files, $files ? 300 : 60);
		if (!empty($res['ok'])) {
			$fileIdOut = self::fileIdFrom($msg['msg_type'], $res['result']);
			if ($files && $fileIdOut && $msg['campaignid']) {
				TelegramBot_Store::db()->pquery('UPDATE vtiger_telegrambot_campaigns SET tg_file_id=? WHERE id=?', array($fileIdOut, $msg['campaignid']));
			}
			if (!$caption) {
				foreach (self::textChunks($body) as $chunk) {
					$r2 = $api->call('sendMessage', array('chat_id' => $chat, 'text' => $chunk));
					if (empty($r2['ok'])) { return $r2; }
				}
			}
		}
		return $res;
	}

	/** Send a queued row and store the outcome (status / error / retry). Returns the updated row. */
	static function sendRow($msg, $bot = null) {
		$bot = $bot ? $bot : TelegramBot_Store::bot($msg['botid']);
		$now = time();
		if (!$bot || !$bot['active']) {
			TelegramBot_Store::update('vtiger_telegrambot_messages', $msg['id'], array('status' => 'failed', 'error' => 'Bot is missing or inactive'));
			return TelegramBot_Store::message($msg['id']);
		}
		$link = TelegramBot_Store::row('SELECT optout, blocked FROM vtiger_telegrambot_links WHERE botid=? AND chat_id=? ORDER BY optout, blocked LIMIT 1', array($bot['id'], $msg['chat_id']));
		if ($link && ($link['optout'] || $link['blocked'])) {
			TelegramBot_Store::update('vtiger_telegrambot_messages', $msg['id'], array('status' => 'skipped', 'error' => $link['optout'] ? 'opt-out' : 'blocked'));
			return TelegramBot_Store::message($msg['id']);
		}
		TelegramBot_Store::update('vtiger_telegrambot_messages', $msg['id'], array('status' => 'sending', 'attempts' => $msg['attempts'] + 1, 'next_try' => $now));
		$fileId = '';
		$res = self::deliver($msg, $bot, $fileId);
		$attempts = $msg['attempts'] + 1;
		if (!empty($res['ok'])) {
			$upd = array('status' => 'sent', 'error' => '', 'sent_at' => time(), 'tg_message_id' => isset($res['result']['message_id']) ? $res['result']['message_id'] : 0);
			if ($fileId) { $upd['tg_file_id'] = $fileId; }
			TelegramBot_Store::update('vtiger_telegrambot_messages', $msg['id'], $upd);
		} else {
			$code = isset($res['error_code']) ? (int)$res['error_code'] : 0;
			$desc = isset($res['description']) ? $res['description'] : 'error';
			$max = max(1, (int)TelegramBot_Store::get('max_attempts'));
			if ($code == 429) {
				$wait = isset($res['parameters']['retry_after']) ? (int)$res['parameters']['retry_after'] : 5;
				$upd = array('status' => $attempts >= $max * 3 ? 'failed' : 'queued', 'next_try' => time() + $wait + 1);
			} else if ($code == 403) {          // bot blocked by the user / user deactivated
				$upd = array('status' => 'failed');
				TelegramBot_Store::db()->pquery('UPDATE vtiger_telegrambot_links SET blocked=1 WHERE botid=? AND chat_id=?', array($bot['id'], $msg['chat_id']));
			} else if ($code == 400 || $code == 404 && strpos($desc, 'Not Found') === false) {
				$upd = array('status' => 'failed');  // bad request: retrying does not help
			} else {                              // network, 5xx, 401/404 (token / proxy problems): retry with backoff
				$upd = array('status' => $attempts >= $max ? 'failed' : 'queued', 'next_try' => time() + min(3600, 30 * pow(2, $attempts - 1)));
				TelegramBot_Store::update('vtiger_telegrambot_bots', $bot['id'], array('last_error' => mb_substr($desc, 0, 500)));
			}
			$upd['error'] = ($code ? "[$code] " : '') . mb_substr($desc, 0, 1000);
			TelegramBot_Store::update('vtiger_telegrambot_messages', $msg['id'], $upd);
		}
		return TelegramBot_Store::message($msg['id']);
	}

	/** Send a bot reply right away (welcome, prompts) and log it in the history. */
	static function reply($bot, $chatId, $text, $contactid = 0, $markup = null) {
		$params = array('chat_id' => $chatId, 'text' => $text);
		if ($markup) { $params['reply_markup'] = $markup; }
		$res = TelegramBot_Api::forBot($bot)->call('sendMessage', $params);
		TelegramBot_Store::addMessage(array('botid' => $bot['id'], 'contactid' => $contactid, 'chat_id' => $chatId, 'direction' => 'out',
			'msg_type' => 'text', 'body' => $text, 'status' => !empty($res['ok']) ? 'sent' : 'failed', 'attempts' => 1, 'sent_at' => time(),
			'error' => !empty($res['ok']) ? '' : (isset($res['description']) ? $res['description'] : ''),
			'tg_message_id' => isset($res['result']['message_id']) ? $res['result']['message_id'] : 0));
		return $res;
	}

	/* ================================================================== incoming */

	static function phoneKey($p) {
		$d = preg_replace('/\D+/', '', (string)$p);
		return strlen($d) >= 9 ? substr($d, -10) : '';
	}

	/** Contacts whose phone fields end with the same 10 digits. */
	static function contactsByPhone($phone) {
		$key = self::phoneKey($phone);
		if ($key === '') { return array(); }
		$fs = self::contactFields();
		$conds = array(); $params = array();
		foreach (array('mobile', 'phone', 'homephone', 'otherphone', 'cf_ss_whatsapp', 'cf_ss_phone_raw') as $name) {
			if (!isset($fs[$name])) { continue; }
			$col = $fs[$name]['tablename'] . '.' . $fs[$name]['columnname'];
			$conds[] = "RIGHT(REGEXP_REPLACE(COALESCE($col,''),'[^0-9]',''),10)=?";
			$params[] = $key;
		}
		$rows = TelegramBot_Store::rows('SELECT DISTINCT vtiger_contactdetails.contactid FROM vtiger_contactdetails
			INNER JOIN vtiger_crmentity e ON e.crmid=vtiger_contactdetails.contactid AND e.deleted=0
			LEFT JOIN vtiger_contactsubdetails ON vtiger_contactsubdetails.contactsubscriptionid=vtiger_contactdetails.contactid
			LEFT JOIN vtiger_contactscf ON vtiger_contactscf.contactid=vtiger_contactdetails.contactid
			WHERE ' . implode(' OR ', $conds), $params);
		return array_map(function ($r) { return (int)$r['contactid']; }, $rows);
	}

	static function phoneKeyboard() {
		return array('keyboard' => array(array(array('text' => TelegramBot_Store::get('phone_button_text'), 'request_contact' => true))),
			'resize_keyboard' => true, 'one_time_keyboard' => true);
	}

	static function welcome($bot, $chatId, $contactid) {
		$data = self::contactsData(array($contactid));
		$text = self::merge(TelegramBot_Store::get('welcome_text'), isset($data[$contactid]) ? $data[$contactid] : array());
		self::reply($bot, $chatId, $text, $contactid, array('remove_keyboard' => true));
	}

	static function handleUpdate($bot, $u) {
		$m = isset($u['message']) ? $u['message'] : null;
		if (!$m || !isset($m['chat']['id']) || (isset($m['chat']['type']) && $m['chat']['type'] != 'private')) { return; }
		$chatId = $m['chat']['id'];
		$from = isset($m['from']) ? $m['from'] : array();
		$text = isset($m['text']) ? trim($m['text']) : '';
		$links = TelegramBot_Store::linksForChat($bot['id'], $chatId);
		$contactid = $links ? (int)$links[0]['contactid'] : 0;

		if (preg_match('/^\/start(?:@\w+)?(?:\s+(\S+))?/', $text, $mm)) {
			$cid = !empty($mm[1]) ? TelegramBot_Store::contactByToken($mm[1]) : 0;
			if ($cid) {
				TelegramBot_Store::saveLink($bot['id'], $cid, $chatId, $from, 'start');
				self::storeIncoming($bot, $m, $cid);
				self::welcome($bot, $chatId, $cid);
			} else if ($links) {
				TelegramBot_Store::db()->pquery('UPDATE vtiger_telegrambot_links SET optout=0, blocked=0 WHERE botid=? AND chat_id=?', array($bot['id'], $chatId));
				self::storeIncoming($bot, $m, $contactid);
				self::welcome($bot, $chatId, $contactid);
			} else {
				self::storeIncoming($bot, $m, 0);
				self::reply($bot, $chatId, TelegramBot_Store::get('ask_phone_text'), 0, self::phoneKeyboard());
			}
			return;
		}
		if (preg_match('/^\/stop\b/', $text)) {
			TelegramBot_Store::db()->pquery('UPDATE vtiger_telegrambot_links SET optout=1 WHERE botid=? AND chat_id=?', array($bot['id'], $chatId));
			self::storeIncoming($bot, $m, $contactid);
			self::reply($bot, $chatId, TelegramBot_Store::get('stop_text'), $contactid, array('remove_keyboard' => true));
			return;
		}
		if (isset($m['contact'])) {
			self::storeIncoming($bot, $m, $contactid);
			$own = isset($m['contact']['user_id']) && isset($from['id']) && $m['contact']['user_id'] == $from['id'];
			if (!$own) {
				self::reply($bot, $chatId, TelegramBot_Store::get('ask_phone_text'), $contactid, self::phoneKeyboard());
				return;
			}
			$ids = self::contactsByPhone($m['contact']['phone_number']);
			if (count($ids) == 1) {
				TelegramBot_Store::saveLink($bot['id'], $ids[0], $chatId, $from, 'phone', $m['contact']['phone_number']);
				TelegramBot_Store::db()->pquery('UPDATE vtiger_telegrambot_messages SET contactid=? WHERE botid=? AND chat_id=? AND contactid=0', array($ids[0], $bot['id'], $chatId));
				self::welcome($bot, $chatId, $ids[0]);
			} else {
				self::reply($bot, $chatId, TelegramBot_Store::get(count($ids) ? 'ambiguous_text' : 'not_found_text'), $contactid, array('remove_keyboard' => true));
			}
			return;
		}
		self::storeIncoming($bot, $m, $contactid);
		if (!$links) {
			// not linked yet: ask for the phone number, at most every 10 minutes
			$recent = TelegramBot_Store::row("SELECT id FROM vtiger_telegrambot_messages WHERE botid=? AND chat_id=? AND direction='out' AND created>?", array($bot['id'], $chatId, time() - 600));
			if (!$recent) { self::reply($bot, $chatId, TelegramBot_Store::get('ask_phone_text'), 0, self::phoneKeyboard()); }
		}
	}

	/** Store an incoming message (downloading its media) in the history. */
	static function storeIncoming($bot, $m, $contactid) {
		$type = 'text'; $body = isset($m['text']) ? $m['text'] : (isset($m['caption']) ? $m['caption'] : '');
		$file = null; $name = ''; $mime = '';
		if (!empty($m['photo'])) { $type = 'photo'; $file = end($m['photo']); $name = 'photo_' . $m['message_id'] . '.jpg'; $mime = 'image/jpeg'; }
		else if (!empty($m['document'])) { $type = 'document'; $file = $m['document']; }
		else if (!empty($m['video'])) { $type = 'video'; $file = $m['video']; $name = 'video_' . $m['message_id'] . '.mp4'; }
		else if (!empty($m['audio'])) { $type = 'audio'; $file = $m['audio']; }
		else if (!empty($m['voice'])) { $type = 'voice'; $file = $m['voice']; $name = 'voice_' . $m['message_id'] . '.ogg'; }
		else if (!empty($m['video_note'])) { $type = 'video'; $file = $m['video_note']; $name = 'video_note_' . $m['message_id'] . '.mp4'; }
		else if (!empty($m['sticker'])) { $type = 'sticker'; $body = isset($m['sticker']['emoji']) ? $m['sticker']['emoji'] : '🖼'; }
		else if (!empty($m['contact'])) { $type = 'contact'; $body = '📱 ' . $m['contact']['phone_number'] . ' ' . (isset($m['contact']['first_name']) ? $m['contact']['first_name'] : ''); }
		else if (!empty($m['location'])) { $type = 'location'; $body = '📍 ' . $m['location']['latitude'] . ',' . $m['location']['longitude']; }
		if ($file) {
			if (!empty($file['file_name'])) { $name = $file['file_name']; }
			if (!empty($file['mime_type'])) { $mime = $file['mime_type']; }
			if ($name === '') { $name = $type . '_' . $m['message_id']; }
		}
		$id = TelegramBot_Store::addMessage(array('botid' => $bot['id'], 'contactid' => $contactid, 'chat_id' => $m['chat']['id'], 'direction' => 'in',
			'msg_type' => $type, 'body' => $body, 'media_name' => $name, 'media_mime' => $mime, 'tg_file_id' => $file ? $file['file_id'] : '',
			'tg_message_id' => $m['message_id'], 'status' => 'received', 'created' => isset($m['date']) ? (int)$m['date'] : time()));
		if ($contactid) { TelegramBot_Store::db()->pquery('UPDATE vtiger_telegrambot_links SET last_in=? WHERE botid=? AND chat_id=?', array(time(), $bot['id'], $m['chat']['id'])); }
		if ($file && (empty($file['file_size']) || $file['file_size'] <= self::DOWNLOAD_MAX)) {
			$api = TelegramBot_Api::forBot($bot);
			$f = $api->call('getFile', array('file_id' => $file['file_id']));
			if (!empty($f['ok']) && !empty($f['result']['file_path'])) {
				$dir = TelegramBot_Store::storageDir('in');
				if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
				$dest = $dir . '/' . bin2hex(random_bytes(6)) . '_' . TelegramBot_Store::safeName($name);
				if ($api->download($f['result']['file_path'], $dest)) {
					TelegramBot_Store::update('vtiger_telegrambot_messages', $id, array('media_path' => TelegramBot_Store::relPath($dest)));
				} else {
					TelegramBot_Store::update('vtiger_telegrambot_messages', $id, array('error' => 'download failed'));
				}
			}
		} else if ($file) {
			TelegramBot_Store::update('vtiger_telegrambot_messages', $id, array('error' => 'file larger than 20 MB (not downloaded)'));
		}
		return $id;
	}

	static function poll($bot, $timeout = 0) {
		$api = TelegramBot_Api::forBot($bot);
		$res = $api->call('getUpdates', array('offset' => $bot['update_offset'], 'timeout' => $timeout, 'allowed_updates' => array('message')), array(), $timeout + 20);
		if (empty($res['ok'])) {
			$desc = isset($res['description']) ? $res['description'] : 'error';
			if (isset($res['error_code']) && $res['error_code'] == 409) { $api->call('deleteWebhook', array()); }   // a webhook blocks getUpdates
			TelegramBot_Store::update('vtiger_telegrambot_bots', $bot['id'], array('last_poll' => time(), 'last_error' => mb_substr($desc, 0, 500)));
			return -1;
		}
		$n = 0;
		foreach ($res['result'] as $u) {
			$bot['update_offset'] = $u['update_id'] + 1;
			TelegramBot_Store::update('vtiger_telegrambot_bots', $bot['id'], array('update_offset' => $bot['update_offset']));
			try { self::handleUpdate($bot, $u); } catch (Exception $e) { error_log('TelegramBot update ' . $u['update_id'] . ': ' . $e->getMessage()); }
			$n++;
		}
		TelegramBot_Store::update('vtiger_telegrambot_bots', $bot['id'], array('last_poll' => time(), 'last_error' => ''));
		return $n;
	}

	/* ================================================================== worker */

	/**
	 * One worker run: poll updates, expand due campaigns, send the queue with rate limiting.
	 * $opts: seconds (time budget), poll (bool), longpoll (bool)
	 */
	static function runWorker($opts = array()) {
		$opts += array('seconds' => (int)TelegramBot_Store::get('worker_seconds'), 'poll' => true, 'longpoll' => true);
		$db = TelegramBot_Store::db();
		$lock = $db->pquery("SELECT GET_LOCK('vtiger_telegrambot_worker', 0) l", array());
		if (!$lock || !$db->query_result($lock, 0, 'l')) { return array('locked' => true); }
		$stats = array('received' => 0, 'sent' => 0, 'failed' => 0, 'expanded' => 0, 'errors' => array());
		$end = microtime(true) + max(5, $opts['seconds']);
		$interval = 1.0 / max(1, min(30, (int)TelegramBot_Store::get('rate_per_sec')));
		$lastChat = array(); $lastSend = 0.0;
		try {
			// rows left in "sending" by a crashed run go back to the queue
			$db->pquery("UPDATE vtiger_telegrambot_messages SET status='queued' WHERE status='sending' AND next_try<?", array(time() - 600));
			do {
				$bots = array();
				foreach (TelegramBot_Store::bots(true) as $b) { $bots[$b['id']] = $b; }
				if (!$bots) { break; }
				$due = TelegramBot_Store::rows("SELECT * FROM vtiger_telegrambot_campaigns WHERE status='scheduled' AND scheduled_at<=? ORDER BY scheduled_at LIMIT 20", array(time()));
				foreach ($due as $c) { $stats['expanded'] += self::expandCampaign($c); }

				$queue = TelegramBot_Store::rows("SELECT * FROM vtiger_telegrambot_messages WHERE status='queued' AND direction='out' AND next_try<=? ORDER BY id LIMIT 200", array(time()));
				$sentThisRound = 0; $deferred = 0;
				foreach ($queue as $msg) {
					if (microtime(true) > $end) { break; }
					$now = microtime(true);
					if (isset($lastChat[$msg['chat_id']]) && $now - $lastChat[$msg['chat_id']] < 1.05) { $deferred++; continue; }   // 1 msg/s per chat
					if ($now - $lastSend < $interval) { usleep((int)(($interval - ($now - $lastSend)) * 1e6)); }
					$bot = isset($bots[$msg['botid']]) ? $bots[$msg['botid']] : null;
					$r = self::sendRow($msg, $bot);
					$lastSend = microtime(true); $lastChat[$msg['chat_id']] = $lastSend;
					if ($r['status'] == 'sent') { $stats['sent']++; } else if ($r['status'] == 'failed') { $stats['failed']++; $stats['errors'][] = $r['error']; }
					$sentThisRound++;
				}
				self::finishCampaigns();
				if ($deferred) { usleep(250000); }

				if ($opts['poll']) {
					$pending = TelegramBot_Store::row("SELECT COUNT(*) n FROM vtiger_telegrambot_messages WHERE status='queued' AND direction='out' AND next_try<=?", array(time() + 2));
					$left = $end - microtime(true);
					$timeout = ($opts['longpoll'] && !$pending['n'] && $left > 8) ? (int)min(25, ($left - 5) / count($bots)) : 0;
					foreach ($bots as $b) {
						$n = self::poll($b, $timeout);
						if ($n > 0) { $stats['received'] += $n; }
						else if ($n < 0) { $stats['errors'][] = 'poll ' . $b['username'] . ': ' . TelegramBot_Store::bot($b['id'])['last_error']; if ($timeout) { sleep(min(10, max(1, (int)$left - 5))); } }
					}
				}
				// nothing sent and nothing waiting for the per-chat limit: stop unless we are long-polling
				if (!$sentThisRound && !$deferred && (!$opts['poll'] || !$opts['longpoll'])) { break; }
			} while (microtime(true) < $end - 1);
		} finally {
			$db->pquery("SELECT RELEASE_LOCK('vtiger_telegrambot_worker')", array());
		}
		$stats['errors'] = array_slice(array_unique($stats['errors']), 0, 5);
		TelegramBot_Store::set('worker_last_run', time());
		TelegramBot_Store::set('worker_last_result', json_encode($stats, JSON_UNESCAPED_UNICODE));
		return $stats;
	}

	/* ================================================================== birthdays */

	static function runBirthdays($force = false) {
		if (!$force && !TelegramBot_Store::get('birthday_enabled')) { return 0; }
		try { $tz = new DateTimeZone(TelegramBot_Store::get('timezone')); } catch (Exception $e) { $tz = new DateTimeZone('Asia/Tehran'); }
		$now = new DateTime('now', $tz);
		$today = $now->format('Y-m-d');
		if (!$force && ((int)$now->format('G') < (int)TelegramBot_Store::get('birthday_hour') || TelegramBot_Store::get('birthday_last') == $today)) { return 0; }
		$md = array($now->format('m-d'));
		if ($now->format('m-d') == '02-28' && !$now->format('L')) { $md[] = '02-29'; }
		$rows = TelegramBot_Store::rows('SELECT s.contactsubscriptionid id FROM vtiger_contactsubdetails s INNER JOIN vtiger_crmentity e ON e.crmid=s.contactsubscriptionid AND e.deleted=0
			WHERE s.birthday IS NOT NULL AND DATE_FORMAT(s.birthday, "%m-%d") IN (' . generateQuestionMarks($md) . ')', $md);
		TelegramBot_Store::set('birthday_last', $today);
		$ids = array_map(function ($r) { return (int)$r['id']; }, $rows);
		if (!$ids) { return 0; }
		$bot = TelegramBot_Store::botOrDefault((int)TelegramBot_Store::get('birthday_botid'));
		if (!$bot) { return 0; }
		self::createCampaign(array('title' => 'تبریک تولد ' . $today, 'kind' => 'birthday', 'botid' => $bot['id'], 'body' => TelegramBot_Store::get('birthday_text'),
			'target_type' => 'ids', 'target_value' => $ids, 'target_label' => 'تولد امروز (' . count($ids) . ')', 'scheduled_at' => time()));
		return count($ids);
	}
}
