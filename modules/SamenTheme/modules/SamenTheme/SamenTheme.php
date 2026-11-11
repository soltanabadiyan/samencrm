<?php
/*+**********************************************************************************
 * SamenTheme – SamenCRM (سامانه مدیریت ارتباط با مشتری) theme for Vtiger CRM 8 (v7 layout), after samencloud.ir.
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
 *  6. the installation wizard (module=Install): marked hooks in Install/InstallPreProcess.tpl and
 *     InstallPostProcess.tpl load install.css/install.js (SamenCRM logo, Persian RTL, brand footer)
 *  5. its own page in Settings > Extensions (status + re-apply); the module has no records,
 *     so it is kept out of the app menus (it used to sit in Tools and opened an empty list = "permission denied")
 ************************************************************************************/
require_once 'vtlib/Vtiger/Module.php';
require_once 'vtlib/Vtiger/Link.php';

class SamenTheme {

	const VERSION = '1.3.4';
	const SETTINGS_LINK = 'index.php?module=SamenTheme&view=Settings&app=SETTINGS';
	const SETTINGS_LABEL = 'پوسته SamenCRM';
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
		// keep the stock page from flashing: nothing of it is painted until login.js has applied the brand
		// (html gets .samen-login-ready); the animation reveals it anyway after 2.5 s if the script failed
		$guard = '{literal}<style>html:not(.samen-login-ready){background:#F4F7FB!important}'
			. 'html:not(.samen-login-ready) body{visibility:hidden;animation:samenLoginReveal 0s 2.5s forwards}'
			. '@keyframes samenLoginReveal{to{visibility:visible}}</style>{/literal}';
		return self::HOOK_BEGIN . $guard . '<link rel="stylesheet" href="' . self::RES . 'login.css?v=' . $v . '">'
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
		foreach (glob('test/templates_c/v7/*Footer.tpl*') ?: array() as $f) { @unlink($f); }
		foreach (glob('test/templates_c/v7/*Install*.tpl*') ?: array() as $f) { @unlink($f); }
	}

	/* ---------------------------------------------------------------- installation wizard
	   module=Install renders its own bare pages (Install/Header.tpl, no header links): a marked block after the
	   page-container start loads install.css/install.js, and the vtiger.com credit line is replaced by ours. */
	const INSTALL_PRE = 'layouts/v7/modules/Install/InstallPreProcess.tpl';
	const INSTALL_POST = 'layouts/v7/modules/Install/InstallPostProcess.tpl';
	const INSTALL_BEGIN = '{* SAMENTHEME:INSTALL:BEGIN *}';
	const INSTALL_END = '{* SAMENTHEME:INSTALL:END *}';

	static function patchInstall() {
		$v = self::VERSION; $ok = true;
		if (is_writable(self::INSTALL_PRE)) {
			$src = self::unpatchedInstall(file_get_contents(self::INSTALL_PRE));
			$hook = self::INSTALL_BEGIN . '{literal}<style>html:not(.samen-install-ready) body{visibility:hidden;animation:samenInstallReveal 0s 2.5s forwards}'
				. '@keyframes samenInstallReveal{to{visibility:visible}}</style>{/literal}'
				. '<link rel="stylesheet" href="' . self::RES . 'install.css?v=' . $v . '">'
				. '<script src="' . self::RES . 'install.js?v=' . $v . '" defer></script>' . self::INSTALL_END;
			file_put_contents(self::INSTALL_PRE, $hook . "\n" . $src);
		} else $ok = false;
		if (is_writable(self::INSTALL_POST)) {
			$src = self::stockInstallFooter(file_get_contents(self::INSTALL_POST));
			$block = self::INSTALL_BEGIN . '<p class="samen-footer" dir="ltr">Powered by SamenCRM - {$VTIGER_VERSION}&nbsp;&nbsp;© 2004 - {date(\'Y\')}'
				. '&nbsp;&nbsp;|&nbsp;&nbsp;<a href="https://samencloud.ir" target="_blank" rel="noopener">SamenCloud Co.</a></p>' . self::INSTALL_END;
			$new = preg_replace('#(<div class="vtFooter">\s*)<p>.*?</p>#s', '$1' . str_replace('$', '\\$', $block), $src, 1, $n);
			if ($n) file_put_contents(self::INSTALL_POST, $new); else $ok = false;
		} else $ok = false;
		self::clearCompiledTemplates();
		return $ok;
	}

	static function unpatchInstall() {
		if (is_writable(self::INSTALL_PRE)) file_put_contents(self::INSTALL_PRE, self::unpatchedInstall(file_get_contents(self::INSTALL_PRE)));
		if (is_writable(self::INSTALL_POST)) {
			file_put_contents(self::INSTALL_POST, self::stockInstallFooter(file_get_contents(self::INSTALL_POST)));
		}
		self::clearCompiledTemplates();
	}

	const INSTALL_STOCK_FOOTER = "<p>\n\t\t\t\t{vtranslate('POWEREDBY')} {\$VTIGER_VERSION}&nbsp;\n\t\t\t\t&copy; 2004 - {date('Y')}&nbsp;\n\t\t\t\t<a href=\"//www.vtiger.com\" target=\"_blank\">vtiger.com</a>\n\t\t\t\t&nbsp;|&nbsp;\n\t\t\t\t<a href=\"#\" onclick=\"window.open('copyright.html', 'copyright', 'height=115,width=575').moveTo(210, 620)\">{vtranslate('LBL_READ_LICENSE')}</a>\n\t\t\t\t&nbsp;|&nbsp;\n\t\t\t\t<a href=\"https://www.vtiger.com/privacy-policy\" target=\"_blank\">{vtranslate('LBL_PRIVACY_POLICY')}</a>\n\t\t\t</p>";

	/** InstallPostProcess.tpl with our credit line swapped back for the stock one */
	static function stockInstallFooter($src) {
		return preg_replace_callback('/' . preg_quote(self::INSTALL_BEGIN, '/') . '.*?' . preg_quote(self::INSTALL_END, '/') . '/s',
			function () { return SamenTheme::INSTALL_STOCK_FOOTER; }, $src);
	}

	static function unpatchedInstall($src) {
		return preg_replace('/\n?' . preg_quote(self::INSTALL_BEGIN, '/') . '.*?' . preg_quote(self::INSTALL_END, '/') . '\n?/s', '', $src);
	}

	/* ---------------------------------------------------------------- footer
	   Vtiger_Footer (layouts/v7/modules/Vtiger/Footer.tpl) has no extension point either: its credit line is
	   replaced by a marked block, and the stock line is put back when the module is disabled/uninstalled. */
	const FOOTER_TPL = 'layouts/v7/modules/Vtiger/Footer.tpl';
	const FOOTER_BEGIN = '{* SAMENTHEME:FOOTER:BEGIN *}';
	const FOOTER_END = '{* SAMENTHEME:FOOTER:END *}';
	const FOOTER_STOCK = "<p>\n\t\tPowered by vtiger CRM - {\$VTIGER_VERSION}&nbsp;&nbsp;© 2004 - {date('Y')}&nbsp;&nbsp;\n\t\t<a href=\"//www.vtiger.com\" target=\"_blank\">Vtiger</a>&nbsp;|&nbsp;\n\t\t<a href=\"https://www.vtiger.com/privacy-policy\" target=\"_blank\">Privacy Policy</a>\n\t</p>";

	static function footerBlock() {
		return self::FOOTER_BEGIN . '<p class="samen-footer" dir="ltr">Powered by SamenCRM - {$VTIGER_VERSION}&nbsp;&nbsp;© 2004 - {date(\'Y\')}'
			. '&nbsp;&nbsp;|&nbsp;&nbsp;<a href="https://samencloud.ir" target="_blank" rel="noopener">SamenCloud Co.</a></p>' . self::FOOTER_END;
	}

	static function patchFooter() {
		$tpl = self::FOOTER_TPL;
		if (!is_writable($tpl)) return false;
		$src = self::unpatchedFooter(file_get_contents($tpl));
		$new = preg_replace('#(<footer class="app-footer">\s*)<p>.*?</p>#s', '$1' . str_replace('$', '\\$', self::footerBlock()), $src, 1, $n);
		if (!$n) return false;
		file_put_contents($tpl, $new);
		self::clearCompiledTemplates();
		return true;
	}

	static function unpatchFooter() {
		$tpl = self::FOOTER_TPL;
		if (is_writable($tpl)) {
			file_put_contents($tpl, self::unpatchedFooter(file_get_contents($tpl)));
			self::clearCompiledTemplates();
		}
	}

	static function unpatchedFooter($src) {
		return preg_replace_callback('/' . preg_quote(self::FOOTER_BEGIN, '/') . '.*?' . preg_quote(self::FOOTER_END, '/') . '/s',
			function () { return SamenTheme::FOOTER_STOCK; }, $src);
	}

	/* ---------------------------------------------------------------- company logo */
	static function setCompanyLogo() {
		global $adb;
		$name = 'samen-company-logo.png';
		if (!is_dir('test/logo')) mkdir('test/logo', 0755, true);
		copy(self::RES . 'img/company-logo.png', 'test/logo/' . $name);
		$adb->pquery("UPDATE vtiger_organizationdetails SET logoname=?, organizationname=IF(organizationname IN ('', 'vtiger', 'Vtiger'), ?, organizationname)
			WHERE organization_id=(SELECT id FROM (SELECT MIN(organization_id) id FROM vtiger_organizationdetails) t)",
			array($name, 'SamenCRM'));
		// drop Vtiger's sample company address (Bangalore, vtiger.com) – only while it is still the sample
		$adb->pquery("UPDATE vtiger_organizationdetails SET address='', city='', state='', code='', country=?, phone='', fax='', website=?
			WHERE website='www.vtiger.com' AND city='Bangalore'", array('ایران', 'https://samencloud.ir'));
	}

	/* ---------------------------------------------------------------- settings page / menus */
	static function registerSettings() {
		global $adb;
		$adb->pquery('DELETE FROM vtiger_settings_field WHERE linkto LIKE ?', array('index.php?module=SamenTheme&%'));
		$b = $adb->pquery("SELECT blockid FROM vtiger_settings_blocks WHERE label IN ('LBL_EXTENSIONS','LBL_OTHER_SETTINGS') ORDER BY FIELD(label,'LBL_EXTENSIONS','LBL_OTHER_SETTINGS') LIMIT 1", array());
		$blockid = $adb->num_rows($b) ? $adb->query_result($b, 0, 'blockid') : 4;
		$seq = $adb->query_result($adb->pquery('SELECT COALESCE(MAX(sequence),0)+1 s FROM vtiger_settings_field WHERE blockid=?', array($blockid)), 0, 's');
		$adb->pquery('INSERT INTO vtiger_settings_field (fieldid, blockid, name, iconpath, description, linkto, sequence, active, pinned) VALUES (?,?,?,?,?,?,?,0,0)',
			array($adb->getUniqueID('vtiger_settings_field'), $blockid, self::SETTINGS_LABEL, '', 'پوسته، راست‌چین، صفحه ورود و لوگو', self::SETTINGS_LINK, $seq));
	}

	static function unregisterSettings() {
		global $adb;
		$adb->pquery('DELETE FROM vtiger_settings_field WHERE linkto LIKE ?', array('index.php?module=SamenTheme&%'));
	}

	/** no records -> not an app menu entry (vtlib puts every <parent> module into the app menu) */
	static function removeFromAppMenu() {
		global $adb;
		$tabid = getTabid('SamenTheme');
		$adb->pquery('DELETE FROM vtiger_app2tab WHERE tabid=?', array($tabid));
		$adb->pquery("UPDATE vtiger_tab SET parent='' WHERE tabid=?", array($tabid));
		if (function_exists('create_tab_data_file')) { create_tab_data_file(); }
	}

	/** what the settings page shows */
	static function status() {
		global $adb;
		$apps = array();
		foreach (glob(self::SKIN . '/*/style.css') ?: array() as $f) {
			$apps[] = array('app' => basename(dirname($f)), 'size' => round(filesize($f) / 1024), 'time' => date('Y-m-d H:i', filemtime($f)));
		}
		$links = array();
		$r = $adb->pquery("SELECT linktype, linkurl FROM vtiger_links WHERE tabid=? AND linktype IN ('HEADERSCRIPT','HEADERCSS')", array(getTabid('SamenTheme')));
		while ($row = $adb->fetch_array($r)) { $links[] = $row['linktype'] . ' ' . $row['linkurl']; }
		$org = $adb->fetch_array($adb->pquery('SELECT organizationname, logoname, website, country FROM vtiger_organizationdetails ORDER BY organization_id LIMIT 1', array()));
		$tpl = is_readable(self::LOGIN_TPL) ? file_get_contents(self::LOGIN_TPL) : '';
		return array(
			'version' => self::VERSION,
			'skin_ok' => count($apps) > 0,
			'apps' => $apps,
			'links' => $links,
			'login_ok' => strpos($tpl, self::HOOK_BEGIN) !== false,
			'login_writable' => is_writable(self::LOGIN_TPL),
			'session_skin' => isset($_SESSION['authenticated_user_skin']) ? $_SESSION['authenticated_user_skin'] : '',
			'org_name' => $org ? $org['organizationname'] : '',
			'logo_ok' => $org && $org['logoname'] === 'samen-company-logo.png' && file_exists('test/logo/samen-company-logo.png'),
			'website' => $org ? $org['website'] : '',
		);
	}

	/* ---------------------------------------------------------------- report print
	   Reports/PrintReport.tpl is a bare page (no header scripts): a marked block in its <head> loads the
	   branded print stylesheet and script (SamenCRM header/footer, RTL, Jalali dates). */
	const PRINT_TPL = 'layouts/v7/modules/Reports/PrintReport.tpl';
	const PRINT_BEGIN = '{* SAMENTHEME:PRINT:BEGIN *}';
	const PRINT_END = '{* SAMENTHEME:PRINT:END *}';

	static function patchPrint() {
		$tpl = self::PRINT_TPL;
		if (!is_writable($tpl)) return false;
		$src = self::unpatchedPrint(file_get_contents($tpl));
		$v = self::VERSION;
		$hook = self::PRINT_BEGIN . '<meta name="viewport" content="width=device-width, initial-scale=1">'
			. '<link rel="stylesheet" href="' . self::RES . 'print.css?v=' . $v . '">'
			. '<script src="' . self::RES . 'print.js?v=' . $v . '"></script>' . self::PRINT_END;
		$pos = stripos($src, '</head>');
		if ($pos === false) return false;
		file_put_contents($tpl, substr($src, 0, $pos) . $hook . substr($src, $pos));
		foreach (glob('test/templates_c/v7/*PrintReport.tpl*') ?: array() as $f) { @unlink($f); }
		return true;
	}

	static function unpatchPrint() {
		$tpl = self::PRINT_TPL;
		if (is_writable($tpl)) {
			file_put_contents($tpl, self::unpatchedPrint(file_get_contents($tpl)));
			foreach (glob('test/templates_c/v7/*PrintReport.tpl*') ?: array() as $f) { @unlink($f); }
		}
	}

	static function unpatchedPrint($src) {
		return preg_replace('/' . preg_quote(self::PRINT_BEGIN, '/') . '.*?' . preg_quote(self::PRINT_END, '/') . '/s', '', $src);
	}

	/** Vtiger 8.4 ships libraries/bootstrap-legacy without img/ – its CSS asks for glyphicons-halflings*.png (404) */
	static function legacyBootstrapIcons() {
		$dst = 'libraries/bootstrap-legacy/img';
		if (!is_dir('libraries/bootstrap-legacy') || !is_dir('libraries/bootstrap/img')) return;
		if (!is_dir($dst)) @mkdir($dst, 0755, true);
		foreach (array('glyphicons-halflings.png', 'glyphicons-halflings-white.png') as $f) {
			if (!file_exists("$dst/$f") && file_exists("libraries/bootstrap/img/$f")) @copy("libraries/bootstrap/img/$f", "$dst/$f");
		}
	}

	/** everything install/update does (idempotent) */
	static function apply() {
		self::legacyBootstrapIcons();
		self::installSkin();
		self::registerLinks();
		self::patchLogin();
		self::patchFooter();
		self::patchPrint();
		self::patchInstall();
		self::setCompanyLogo();
		self::registerSettings();
		self::removeFromAppMenu();
	}

	/* ---------------------------------------------------------------- vtlib events */
	function vtlib_handler($moduleName, $eventType) {
		if (in_array($eventType, array('module.postinstall', 'module.postupdate', 'module.enabled'))) {
			self::apply();
		} else if (in_array($eventType, array('module.disabled', 'module.preuninstall'))) {
			self::unregisterLinks();
			self::unpatchLogin();
			self::unpatchFooter();
			self::unpatchPrint();
			self::unpatchInstall();
			self::unregisterSettings();
		}
	}
}
