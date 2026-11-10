<?php
/* TelegramBot – permission helpers shared by views and actions. */
require_once 'modules/TelegramBot/lib/Service.php';

class TelegramBot_Access {
	static function isAdmin() {
		$u = Users_Record_Model::getCurrentUserModel();
		return $u && $u->isAdminUser();
	}
	/** Module enabled and allowed by the user's profile. */
	static function canUse() {
		if (!vtlib_isModuleActive('TelegramBot')) { return false; }
		if (self::isAdmin()) { return true; }
		$p = Users_Privileges_Model::getCurrentUserPrivilegesModel();
		return $p->hasModulePermission(getTabid('TelegramBot')) && $p->hasModulePermission(getTabid('Contacts'));
	}
	static function canSeeContact($id) {
		return $id && (self::isAdmin() || Users_Privileges_Model::isPermitted('Contacts', 'DetailView', $id));
	}
	static function deny() { throw new AppException(vtranslate('LBL_PERMISSION_DENIED')); }
	static function requireUse() { if (!self::canUse()) { self::deny(); } }
	static function requireAdmin() { if (!self::canUse() || !self::isAdmin()) { self::deny(); } }
}
