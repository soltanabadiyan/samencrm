<?php
/*+**********************************************************************************
 * TelegramBot – minimal Telegram Bot API client (curl).
 * api.telegram.org is filtered from Iranian servers, so the client supports
 *   - a proxy (setting "proxy"): http://host:port, https://..., socks5h://user:pass@host:port
 *   - a different API base URL (setting "api_base"), e.g. a reverse proxy / Cloudflare Worker that forwards
 *     /bot<token>/<method> and /file/bot<token>/<path> to api.telegram.org.
 ************************************************************************************/

class TelegramBot_Api {

	protected $token;
	protected $base;
	protected $proxy;
	public $lastHttp = 0;

	function __construct($token, $base = null, $proxy = null) {
		$this->token = trim($token);
		$this->base = rtrim($base !== null ? $base : TelegramBot_Store::get('api_base'), '/');
		if ($this->base === '') { $this->base = 'https://api.telegram.org'; }
		$this->proxy = trim($proxy !== null ? $proxy : (string)TelegramBot_Store::get('proxy'));
	}

	static function forBot($bot) { return new self($bot['token']); }

	protected function curl($url, $timeout) {
		$ch = curl_init($url);
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_USERAGENT => 'SamenCRM-TelegramBot/1.0',
		));
		if ($this->proxy !== '') {
			curl_setopt($ch, CURLOPT_PROXY, $this->proxy);   // scheme in the URL selects HTTP / SOCKS5
		}
		return $ch;
	}

	/**
	 * Call a Bot API method. $files = array(param => absolute path) are sent as multipart uploads.
	 * Returns the decoded Telegram reply: array('ok'=>bool, 'result'=>..., 'error_code'=>int, 'description'=>..., 'parameters'=>...).
	 * Network errors come back as ok=false, error_code=0.
	 */
	function call($method, $params = array(), $files = array(), $timeout = 60) {
		$ch = $this->curl($this->base . '/bot' . $this->token . '/' . $method, $timeout);
		foreach ($params as $k => $v) {
			if (is_array($v)) { $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE); }
			else if (is_bool($v)) { $params[$k] = $v ? 'true' : 'false'; }
		}
		if ($files) {
			foreach ($files as $k => $f) {
				$name = isset($f['name']) ? $f['name'] : basename($f['path']);
				$mime = !empty($f['mime']) ? $f['mime'] : 'application/octet-stream';
				$params[$k] = new CURLFile($f['path'], $mime, $name);
			}
			curl_setopt($ch, CURLOPT_POSTFIELDS, $params);              // multipart/form-data
		} else {
			curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
		}
		$raw = curl_exec($ch);
		$this->lastHttp = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$err = curl_error($ch);
		curl_close($ch);
		if ($raw === false || $raw === '') {
			return array('ok' => false, 'error_code' => 0, 'description' => 'Network error: ' . ($err ? $err : 'empty reply'));
		}
		$data = json_decode($raw, true);
		if (!is_array($data)) {
			return array('ok' => false, 'error_code' => $this->lastHttp, 'description' => 'Bad reply (HTTP ' . $this->lastHttp . '): ' . mb_substr(strip_tags($raw), 0, 200));
		}
		return $data;
	}

	/** Download a file (getFile file_path) to $dest. Returns true/false. */
	function download($filePath, $dest) {
		$ch = $this->curl($this->base . '/file/bot' . $this->token . '/' . $filePath, 120);
		$fh = fopen($dest, 'wb');
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);   // must come before CURLOPT_FILE, it resets the output target
		curl_setopt($ch, CURLOPT_FILE, $fh);
		$ok = curl_exec($ch);
		$http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);
		fclose($fh);
		if (!$ok || $http != 200) { @unlink($dest); return false; }
		return true;
	}
}
