<?php
/*+**********************************************************************************
 * TelegramBot – database layer (vtiger_telegrambot_* tables) and small helpers.
 * All times are unix timestamps (INT); they are shown in the user's time zone.
 ************************************************************************************/

class TelegramBot_Store {

	static $DEFAULTS = array(
		'api_base' => 'https://api.telegram.org',
		'proxy' => '',                 // e.g. socks5h://127.0.0.1:1080  or  http://user:pass@host:3128
		'rate_per_sec' => '25',        // global send rate (Telegram allows ~30/s)
		'max_attempts' => '5',
		'worker_seconds' => '45',      // how long one cron run keeps polling/sending
		'timezone' => 'Asia/Tehran',
		'welcome_text' => "سلام \$firstname\$ عزیز 🌹\nحساب تلگرام شما به پرونده‌تان در سامانه سامن متصل شد. از این پس پیام‌ها و اطلاعیه‌ها را از همین‌جا دریافت می‌کنید.\nبرای لغو دریافت پیام: /stop",
		'ask_phone_text' => "سلام! برای اتصال به پرونده‌تان در سامانه سامن، لطفاً با دکمه زیر شماره تلفن خود را ارسال کنید، یا از لینک اختصاصی که برایتان فرستاده شده استفاده کنید.",
		'phone_button_text' => '📱 ارسال شماره تلفن',
		'not_found_text' => "متأسفانه پرونده‌ای با این شماره پیدا نشد. لطفاً از لینک اختصاصی خود استفاده کنید یا با کارشناس سامن تماس بگیرید.",
		'ambiguous_text' => "این شماره به چند پرونده متصل است. لطفاً از لینک اختصاصی خود استفاده کنید.",
		'stop_text' => "دریافت پیام‌ها لغو شد. برای فعال‌سازی دوباره /start را بزنید.",
		'birthday_enabled' => '0',
		'birthday_hour' => '9',
		'birthday_botid' => '',
		'birthday_text' => "\$firstname\$ عزیز، تولدت مبارک! 🎂🎉\nبهترین آرزوها را از طرف خانواده سامن برایت داریم.",
		'birthday_last' => '',
		'worker_last_run' => '',
		'worker_last_result' => '',
	);

	static function db() { return PearDatabase::getInstance(); }

	static function createTables() {
		$db = self::db();
		$sql = array(
			"CREATE TABLE IF NOT EXISTS vtiger_telegrambot_bots (
				id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
				title VARCHAR(100) NOT NULL DEFAULT '',
				username VARCHAR(64) NOT NULL DEFAULT '',
				tg_botid BIGINT NOT NULL DEFAULT 0,
				token VARCHAR(128) NOT NULL,
				active TINYINT(1) NOT NULL DEFAULT 1,
				is_default TINYINT(1) NOT NULL DEFAULT 0,
				update_offset BIGINT NOT NULL DEFAULT 0,
				last_poll INT NOT NULL DEFAULT 0,
				last_error TEXT,
				created INT NOT NULL DEFAULT 0
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
			"CREATE TABLE IF NOT EXISTS vtiger_telegrambot_settings (
				name VARCHAR(64) NOT NULL PRIMARY KEY,
				value MEDIUMTEXT
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
			"CREATE TABLE IF NOT EXISTS vtiger_telegrambot_links (
				id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
				botid INT NOT NULL,
				contactid INT NOT NULL,
				chat_id BIGINT NOT NULL,
				tg_username VARCHAR(64) NOT NULL DEFAULT '',
				tg_name VARCHAR(255) NOT NULL DEFAULT '',
				phone VARCHAR(32) NOT NULL DEFAULT '',
				linked_via VARCHAR(16) NOT NULL DEFAULT '',
				optout TINYINT(1) NOT NULL DEFAULT 0,
				blocked TINYINT(1) NOT NULL DEFAULT 0,
				linked_at INT NOT NULL DEFAULT 0,
				last_in INT NOT NULL DEFAULT 0,
				UNIQUE KEY bot_contact (botid, contactid),
				KEY bot_chat (botid, chat_id),
				KEY contact (contactid)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
			"CREATE TABLE IF NOT EXISTS vtiger_telegrambot_deeplinks (
				contactid INT NOT NULL PRIMARY KEY,
				token VARCHAR(40) NOT NULL,
				created INT NOT NULL DEFAULT 0,
				UNIQUE KEY token (token)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
			"CREATE TABLE IF NOT EXISTS vtiger_telegrambot_messages (
				id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
				botid INT NOT NULL DEFAULT 0,
				contactid INT NOT NULL DEFAULT 0,
				chat_id BIGINT NOT NULL DEFAULT 0,
				direction CHAR(3) NOT NULL DEFAULT 'out',
				msg_type VARCHAR(16) NOT NULL DEFAULT 'text',
				body MEDIUMTEXT,
				media_path VARCHAR(500) NOT NULL DEFAULT '',
				media_name VARCHAR(255) NOT NULL DEFAULT '',
				media_mime VARCHAR(100) NOT NULL DEFAULT '',
				tg_file_id VARCHAR(255) NOT NULL DEFAULT '',
				tg_message_id BIGINT NOT NULL DEFAULT 0,
				status VARCHAR(16) NOT NULL DEFAULT 'queued',
				error TEXT,
				attempts INT NOT NULL DEFAULT 0,
				next_try INT NOT NULL DEFAULT 0,
				campaignid INT NOT NULL DEFAULT 0,
				userid INT NOT NULL DEFAULT 0,
				created INT NOT NULL DEFAULT 0,
				sent_at INT NOT NULL DEFAULT 0,
				KEY queue (status, next_try),
				KEY contact (contactid, id),
				KEY chat (botid, chat_id),
				KEY campaign (campaignid, status)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
			"CREATE TABLE IF NOT EXISTS vtiger_telegrambot_campaigns (
				id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
				title VARCHAR(255) NOT NULL DEFAULT '',
				kind VARCHAR(16) NOT NULL DEFAULT 'manual',
				botid INT NOT NULL DEFAULT 0,
				msg_type VARCHAR(16) NOT NULL DEFAULT 'text',
				body MEDIUMTEXT,
				media_path VARCHAR(500) NOT NULL DEFAULT '',
				media_name VARCHAR(255) NOT NULL DEFAULT '',
				media_mime VARCHAR(100) NOT NULL DEFAULT '',
				tg_file_id VARCHAR(255) NOT NULL DEFAULT '',
				target_type VARCHAR(16) NOT NULL DEFAULT 'ids',
				target_value MEDIUMTEXT,
				target_label VARCHAR(500) NOT NULL DEFAULT '',
				scheduled_at INT NOT NULL DEFAULT 0,
				status VARCHAR(16) NOT NULL DEFAULT 'scheduled',
				total INT NOT NULL DEFAULT 0,
				skipped INT NOT NULL DEFAULT 0,
				error TEXT,
				userid INT NOT NULL DEFAULT 0,
				created INT NOT NULL DEFAULT 0,
				processed_at INT NOT NULL DEFAULT 0,
				KEY due (status, scheduled_at)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
			"CREATE TABLE IF NOT EXISTS vtiger_telegrambot_templates (
				id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
				title VARCHAR(255) NOT NULL DEFAULT '',
				body MEDIUMTEXT,
				userid INT NOT NULL DEFAULT 0,
				created INT NOT NULL DEFAULT 0
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
		);
		foreach ($sql as $q) { $db->pquery($q, array()); }
	}

	/* ------------------------------------------------------------ settings */
	static $cache = null;
	static function settings($fresh = false) {
		if (self::$cache === null || $fresh) {
			$out = self::$DEFAULTS;
			$r = self::db()->pquery('SELECT name, value FROM vtiger_telegrambot_settings', array());
			while ($row = self::db()->fetchByAssoc($r, -1, false)) { $out[$row['name']] = self::dec($row['value']); }
			self::$cache = $out;
		}
		return self::$cache;
	}
	static function get($name) { $s = self::settings(); return isset($s[$name]) ? $s[$name] : null; }
	static function set($name, $value) {
		self::db()->pquery('INSERT INTO vtiger_telegrambot_settings (name, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=VALUES(value)', array($name, self::enc((string)$value)));
		if (self::$cache !== null) { self::$cache[$name] = (string)$value; }
	}

	/* ------------------------------------------------------------ rows */
	/* The Vtiger DB connection is utf8 (3-byte), so emoji and other 4-byte characters are stored as
	   &#xHHHHH; entities (enc) and turned back into characters when read (dec). */
	static function enc($v) {
		if (!is_string($v)) { return $v; }
		return preg_replace_callback('/[\x{10000}-\x{10FFFF}]/u', function ($m) { return '&#x' . strtoupper(dechex(mb_ord($m[0], 'UTF-8'))) . ';'; }, $v);
	}
	static function dec($v) {
		if (!is_string($v) || strpos($v, '&#x') === false) { return $v; }
		return preg_replace_callback('/&#x(10[0-9A-F]{4}|[1-9A-F][0-9A-F]{4});/', function ($m) { return mb_chr(hexdec($m[1]), 'UTF-8'); }, $v);
	}
	static function rows($sql, $params = array()) {
		$db = self::db();
		$r = $db->pquery($sql, array_map(array(__CLASS__, 'enc'), $params));
		$out = array();
		if ($r) { while ($row = $db->fetchByAssoc($r, -1, false)) { $out[] = array_map(array(__CLASS__, 'dec'), $row); } }
		return $out;
	}
	static function row($sql, $params = array()) { $r = self::rows($sql, $params); return $r ? $r[0] : null; }
	static function insert($table, $data) {
		$db = self::db();
		$cols = array_keys($data);
		$db->pquery('INSERT INTO ' . $table . ' (' . implode(',', $cols) . ') VALUES (' . generateQuestionMarks($cols) . ')', array_map(array(__CLASS__, 'enc'), array_values($data)));
		return (int)$db->getLastInsertID();
	}
	static function update($table, $id, $data) {
		$set = array();
		foreach ($data as $k => $v) { $set[] = "$k=?"; }
		$params = array_map(array(__CLASS__, 'enc'), array_values($data)); $params[] = $id;
		self::db()->pquery('UPDATE ' . $table . ' SET ' . implode(',', $set) . ' WHERE id=?', $params);
	}

	/* ------------------------------------------------------------ bots */
	static function bots($activeOnly = false) {
		return self::rows('SELECT * FROM vtiger_telegrambot_bots' . ($activeOnly ? ' WHERE active=1' : '') . ' ORDER BY is_default DESC, id');
	}
	static function bot($id) { return self::row('SELECT * FROM vtiger_telegrambot_bots WHERE id=?', array($id)); }
	static function defaultBot() {
		return self::row('SELECT * FROM vtiger_telegrambot_bots WHERE active=1 ORDER BY is_default DESC, id LIMIT 1');
	}
	static function botOrDefault($id) {
		$b = $id ? self::bot($id) : null;
		return ($b && $b['active']) ? $b : self::defaultBot();
	}
	static function maskToken($t) { return strlen($t) > 12 ? substr($t, 0, 6) . '…' . substr($t, -4) : '••••'; }

	/* ------------------------------------------------------------ deep links */
	static function deepToken($contactid, $create = true) {
		$row = self::row('SELECT token FROM vtiger_telegrambot_deeplinks WHERE contactid=?', array($contactid));
		if ($row) { return $row['token']; }
		if (!$create) { return null; }
		// Telegram start parameter: [A-Za-z0-9_-], max 64 chars. "c" + id + "_" + random -> readable and unguessable
		$token = 'c' . (int)$contactid . '_' . substr(bin2hex(random_bytes(8)), 0, 12);
		self::db()->pquery('INSERT IGNORE INTO vtiger_telegrambot_deeplinks (contactid, token, created) VALUES (?,?,?)', array($contactid, $token, time()));
		return self::deepToken($contactid, false);
	}
	static function contactByToken($token) {
		$row = self::row('SELECT d.contactid FROM vtiger_telegrambot_deeplinks d INNER JOIN vtiger_crmentity e ON e.crmid=d.contactid AND e.deleted=0 WHERE d.token=?', array($token));
		return $row ? (int)$row['contactid'] : 0;
	}
	static function deepLink($bot, $contactid) {
		if (!$bot || !$bot['username']) { return ''; }
		return 'https://t.me/' . $bot['username'] . '?start=' . self::deepToken($contactid);
	}

	/* ------------------------------------------------------------ links */
	static function linksForContact($contactid) {
		return self::rows('SELECT l.*, b.title bot_title, b.username bot_username FROM vtiger_telegrambot_links l
			LEFT JOIN vtiger_telegrambot_bots b ON b.id=l.botid WHERE l.contactid=? ORDER BY l.botid', array($contactid));
	}
	static function link($botid, $contactid) {
		return self::row('SELECT * FROM vtiger_telegrambot_links WHERE botid=? AND contactid=?', array($botid, $contactid));
	}
	static function linksForChat($botid, $chatId) {
		return self::rows('SELECT l.* FROM vtiger_telegrambot_links l INNER JOIN vtiger_crmentity e ON e.crmid=l.contactid AND e.deleted=0
			WHERE l.botid=? AND l.chat_id=? ORDER BY l.linked_at DESC', array($botid, $chatId));
	}
	static function saveLink($botid, $contactid, $chatId, $from, $via, $phone = '') {
		$name = trim((isset($from['first_name']) ? $from['first_name'] : '') . ' ' . (isset($from['last_name']) ? $from['last_name'] : ''));
		$user = isset($from['username']) ? $from['username'] : '';
		self::db()->pquery('INSERT INTO vtiger_telegrambot_links (botid, contactid, chat_id, tg_username, tg_name, phone, linked_via, optout, blocked, linked_at)
			VALUES (?,?,?,?,?,?,?,0,0,?) ON DUPLICATE KEY UPDATE chat_id=VALUES(chat_id), tg_username=VALUES(tg_username), tg_name=VALUES(tg_name),
			phone=IF(VALUES(phone)<>"",VALUES(phone),phone), linked_via=VALUES(linked_via), optout=0, blocked=0, linked_at=VALUES(linked_at)',
			array($botid, $contactid, $chatId, self::enc($user), self::enc($name), $phone, $via, time()));
	}

	/* ------------------------------------------------------------ messages */
	static function addMessage($data) {
		$data += array('created' => time(), 'status' => 'queued', 'direction' => 'out', 'msg_type' => 'text');
		return self::insert('vtiger_telegrambot_messages', $data);
	}
	static function message($id) { return self::row('SELECT * FROM vtiger_telegrambot_messages WHERE id=?', array($id)); }

	static function history($contactid, $limit = 200) {
		// messages stored for this contact, plus messages of chats linked to it (e.g. replies stored before linking)
		return array_reverse(self::rows('SELECT m.*, u.first_name ufirst, u.last_name ulast FROM vtiger_telegrambot_messages m
			LEFT JOIN vtiger_users u ON u.id=m.userid
			WHERE m.contactid=? OR (m.contactid=0 AND (m.botid, m.chat_id) IN (SELECT botid, chat_id FROM vtiger_telegrambot_links WHERE contactid=?))
			ORDER BY m.id DESC LIMIT ' . (int)$limit, array($contactid, $contactid)));
	}

	/* ------------------------------------------------------------ files */
	static function storageDir($sub = '') {
		global $root_directory;
		$root = rtrim($root_directory ? $root_directory : getcwd(), '/') . '/storage/TelegramBot';
		return $sub ? $root . '/' . $sub . '/' . date('Y/m') : $root;
	}
	static function safeName($name) {
		$name = preg_replace('/[\x00-\x1f\/\\\\:*?"<>|]+/u', '_', basename((string)$name));
		$name = trim($name, ". \t");
		return $name === '' ? 'file' : mb_substr($name, -120);
	}
	/** Store an uploaded / downloaded file under storage/TelegramBot/<sub>/Y/m and return the path relative to the Vtiger root. */
	static function storeFile($srcPath, $origName, $sub, $move = false) {
		$dir = self::storageDir($sub);
		if (!is_dir($dir)) { @mkdir($dir, 0775, true); }
		$dest = $dir . '/' . bin2hex(random_bytes(6)) . '_' . self::safeName($origName);
		$ok = $move ? (is_uploaded_file($srcPath) ? move_uploaded_file($srcPath, $dest) : rename($srcPath, $dest)) : copy($srcPath, $dest);
		if (!$ok) { throw new Exception('Cannot store file ' . $origName); }
		return self::relPath($dest);
	}
	static function relPath($abs) {
		global $root_directory;
		$root = rtrim($root_directory ? $root_directory : getcwd(), '/') . '/';
		return strpos($abs, $root) === 0 ? substr($abs, strlen($root)) : $abs;
	}
	/** Absolute path of a stored media file; only files inside the Vtiger storage dir are allowed. */
	static function absPath($rel) {
		global $root_directory;
		if ($rel === '' || $rel === null) { return null; }
		$root = rtrim($root_directory ? $root_directory : getcwd(), '/');
		$abs = realpath($rel[0] === '/' ? $rel : $root . '/' . $rel);
		$storage = realpath($root . '/storage');
		if (!$abs || !$storage || strpos($abs, $storage . '/') !== 0 || !is_file($abs)) { return null; }
		return $abs;
	}

	/** File of a Vtiger Document (internal file), or null. */
	static function documentFile($notesid) {
		$row = self::row("SELECT n.notesid, n.title, n.filename, n.filelocationtype, a.attachmentsid, a.name, a.path, a.type, a.storedname
			FROM vtiger_notes n INNER JOIN vtiger_crmentity e ON e.crmid=n.notesid AND e.deleted=0
			INNER JOIN vtiger_seattachmentsrel s ON s.crmid=n.notesid INNER JOIN vtiger_attachments a ON a.attachmentsid=s.attachmentsid
			WHERE n.notesid=? AND n.filelocationtype='I' LIMIT 1", array($notesid));
		if (!$row) { return null; }
		$stored = $row['storedname'] !== null && $row['storedname'] !== '' ? $row['storedname'] : $row['name'];
		$rel = $row['path'] . $row['attachmentsid'] . '_' . $stored;
		if (!self::absPath($rel)) { return null; }
		return array('path' => $rel, 'name' => html_entity_decode($row['name'], ENT_QUOTES, 'UTF-8'), 'mime' => $row['type'], 'title' => $row['title']);
	}

	/* ------------------------------------------------------------ time */
	static function userTz() {
		global $current_user;
		$tz = ($current_user && !empty($current_user->time_zone)) ? $current_user->time_zone : self::get('timezone');
		try { return new DateTimeZone($tz); } catch (Exception $e) { return new DateTimeZone('UTC'); }
	}
	/** "Y-m-d H:i" in the user's time zone, date part in the user's date format (PersianCalendar turns it into Jalali). */
	static function fmtTime($ts) {
		if (!$ts) { return ''; }
		$d = new DateTime('@' . (int)$ts);
		$d->setTimezone(self::userTz());
		$date = $d->format('Y-m-d');
		if (class_exists('DateTimeField')) { $date = DateTimeField::convertToUserFormat($date); }
		return $date . ' ' . $d->format('H:i');
	}
	/** Date in the user's format + "HH:MM" (user time zone) -> unix timestamp. */
	static function parseUserTime($date, $time) {
		if (class_exists('DateTimeField') && $date !== '') { $date = DateTimeField::convertToDBFormat($date); }
		if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) { return 0; }
		if (!preg_match('/^\d{1,2}:\d{2}$/', $time)) { $time = '00:00'; }
		$d = DateTime::createFromFormat('Y-m-d H:i', "$date $time", self::userTz());
		return $d ? $d->getTimestamp() : 0;
	}
}
