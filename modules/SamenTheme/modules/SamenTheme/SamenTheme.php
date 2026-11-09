<?php
/*+**********************************************************************************
 * SamenTheme – SamenStudent theme for Vtiger CRM 8 (v7 layout), after samencloud.ir.
 * Vtiger extension module (vtlib). It ships and maintains:
 *  1. the official Vtiger 8 *custom skin*  layouts/v7/custom_skins/samen/<app>/style.css
 *     (brand palette compiled from Vtiger's own LESS + theme + full RTL for Persian users).
 *     Vtiger needs a complete skin tree there, so the stock images/fonts are copied next to it.
 *  2. a HEADERSCRIPT link (theme.js: favicon, html lang/dir, a few untranslatable labels)
 *  3. a marked hook in layouts/v7/modules/Users/Login.tpl that loads login.css/login.js
 *     (brand login page; preselects the "samen" skin, which Users/actions/Login.php keeps in the session).
 *     Vtiger has no extension point for the login page, so this is the only core file touched;
 *     it is re-applied on update and removed when the module is disabled/uninstalled.
 *  4. the company logo (Settings > Company Details) = resources/img/company-logo.png
 ************************************************************************************/
require_once 'vtlib/Vtiger/Module.php';
require_once 'vtlib/Vtiger/Link.php';

class SamenTheme {

	const VERSION = '1.0.0';
	const RES = 'layouts/v7/modules/SamenTheme/resources/';
	const SKIN = 'layouts/v7/custom_skins/samen';
	const LOGIN_TPL = 'layouts/v7/modules/Users/Login.tpl';
	const HOOK_BEGIN = '{* SAMENTHEME:BEGIN *}';
	const HOOK_END = '{* SAMENTHEME:END *}';

	/* ---------------------------------------------------------------- header links */
	static function registerLinks() {
		$tabid = getTabid('SamenTheme');
		self::unregisterLinks();
		Vtiger_Link::addLink($tabid, 'HEADERSCRIPT', 'SamenThemeJs', self::RES . 'theme.js?v=' . self::VERSION, '', 1);
	}

	static function unregisterLinks() {
		global $adb;
		$adb->pquery("DELETE FROM vtiger_links WHERE tabid=? AND linktype IN ('HEADERSCRIPT','HEADERCSS')", array(getTabid('SamenTheme')));
	}

	/* ---------------------------------------------------------------- custom skin */
	static function installSkin() {
		// complete the skin tree with the stock assets (style.css files of the package are kept)
		self::copyTree('layouts/v7/skins', self::SKIN, false);
	}

	static function copyTree($src, $dst, $overwrite) {
		if (!is_dir($src)) return;
		if (!is_dir($dst)) mkdir($dst, 0755, true);
		foreach (scandir($src) as $f) {
			if ($f === '.' || $f === '..') continue;
			$s = "$src/$f"; $d = "$dst/$f";
			if (is_dir($s)) {
				self::copyTree($s, $d, $overwrite);
			} else if ($overwrite || !file_exists($d)) {
				// never take the stock app stylesheets: ours replace them
				if (preg_match('#/(style\.css|style\.css\.map|style\.less|variables\.less)$#', $d) && file_exists($d)) continue;
				copy($s, $d);
			}
		}
	}

	/* ---------------------------------------------------------------- login page hook */
	static function loginHook() {
		$v = self::VERSION;
		return self::HOOK_BEGIN . '<link rel="stylesheet" href="' . self::RES . 'login.css?v=' . $v . '">'
			. '<script src="' . self::RES . 'login.js?v=' . $v . '" defer></script>' . self::HOOK_END;
	}

	static function patchLogin() {
		$tpl = self::LOGIN_TPL;
		if (!is_writable($tpl)) return false;
		$src = self::unpatchedLogin(file_get_contents($tpl));
		$pos = strpos($src, '{strip}');
		$src = ($pos === false) ? self::loginHook() . $src
			: substr($src, 0, $pos + 7) . "\n\t" . self::loginHook() . substr($src, $pos + 7);
		file_put_contents($tpl, $src);
		self::clearCompiledTemplates();
		return true;
	}

	static function unpatchLogin() {
		$tpl = self::LOGIN_TPL;
		if (is_writable($tpl)) {
			file_put_contents($tpl, self::unpatchedLogin(file_get_contents($tpl)));
			self::clearCompiledTemplates();
		}
	}

	static function unpatchedLogin($src) {
		return preg_replace('/\n?\t?' . preg_quote(self::HOOK_BEGIN, '/') . '.*?' . preg_quote(self::HOOK_END, '/') . '/s', '', $src);
	}

	static function clearCompiledTemplates() {
		foreach (glob('test/templates_c/v7/*Login.tpl*') ?: array() as $f) { @unlink($f); }
	}

	/* ---------------------------------------------------------------- company logo */
	static function setCompanyLogo() {
		global $adb;
		$name = 'samen-company-logo.png';
		if (!is_dir('test/logo')) mkdir('test/logo', 0755, true);
		copy(self::RES . 'img/company-logo.png', 'test/logo/' . $name);
		$adb->pquery('UPDATE vtiger_organizationdetails SET logoname=?, organizationname=? WHERE organization_id=(SELECT id FROM (SELECT MIN(organization_id) id FROM vtiger_organizationdetails) t)',
			array($name, 'ثامن استودنت'));
	}

	/* ---------------------------------------------------------------- vtlib events */
	function vtlib_handler($moduleName, $eventType) {
		if (in_array($eventType, array('module.postinstall', 'module.postupdate', 'module.enabled'))) {
			self::installSkin();
			self::registerLinks();
			self::patchLogin();
			if ($eventType == 'module.postinstall') self::setCompanyLogo();
		} else if (in_array($eventType, array('module.disabled', 'module.preuninstall'))) {
			self::unregisterLinks();
			self::unpatchLogin();
		}
	}
}
