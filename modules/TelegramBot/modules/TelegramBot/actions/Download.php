<?php
/* TelegramBot – serve the media file of a message (index.php?module=TelegramBot&action=Download&id=...). */
require_once 'modules/TelegramBot/lib/Access.php';

class TelegramBot_Download_Action extends Vtiger_Action_Controller {

	function requiresPermission(Vtiger_Request $request) { return array(); }
	function checkPermission(Vtiger_Request $request) { TelegramBot_Access::requireUse(); return true; }
	function validateRequest(Vtiger_Request $request) { return $request->validateReadAccess(); }

	function process(Vtiger_Request $request) {
		$m = TelegramBot_Store::message((int)$request->get('id'));
		if (!$m || ($m['contactid'] && !TelegramBot_Access::canSeeContact($m['contactid'])) || (!$m['contactid'] && !TelegramBot_Access::isAdmin())) {
			TelegramBot_Access::deny();
		}
		$abs = TelegramBot_Store::absPath($m['media_path']);
		if (!$abs) { header('HTTP/1.1 404 Not Found'); echo 'File not found'; return; }
		$mime = $m['media_mime'] ? $m['media_mime'] : (function_exists('mime_content_type') ? mime_content_type($abs) : 'application/octet-stream');
		$inline = $request->get('inline') && preg_match('#^(image|video|audio)/#', $mime);
		while (ob_get_level()) { ob_end_clean(); }
		header('Content-Type: ' . $mime);
		header('Content-Length: ' . filesize($abs));
		header('X-Content-Type-Options: nosniff');
		$name = $m['media_name'] ? $m['media_name'] : basename($abs);
		header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . "; filename*=UTF-8''" . rawurlencode($name));
		readfile($abs);
	}
}
