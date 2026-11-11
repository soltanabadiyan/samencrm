/* SamenTheme – installation wizard (module=Install): SamenCRM logo and art, Persian RTL page,
   no vtiger.com links. Loaded by the SAMENTHEME:INSTALL hook in Install/InstallPreProcess.tpl. */
(function (document) {
	'use strict';
	var BASE = 'layouts/v7/modules/SamenTheme/resources/';
	// words the installer prints without vtranslate()
	var FA = {
		'en_us': 'English', 'fa_ir': 'فارسی', 'es_es': 'Español', 'fr_fr': 'Français', 'hu_hu': 'Magyar',
		'pl_pl': 'Polski', 'pt_br': 'Português (Brasil)', 'de_de': 'Deutsch', 'it_it': 'Italiano', 'nl_nl': 'Nederlands', 'ru_ru': 'Русский',
		'Yes': 'بله', 'No': 'خیر', 'On': 'روشن', 'Off': 'خاموش', 'Next': 'بعدی', 'Back': 'بازگشت',
		'Install': 'نصب', 'Help-Icon': ''
	};
	function tr(root) {
		var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false), n;
		while ((n = w.nextNode())) {
			var k = n.nodeValue.trim();
			if (k && Object.prototype.hasOwnProperty.call(FA, k)) { n.nodeValue = n.nodeValue.replace(k, FA[k]); }
		}
		Array.prototype.forEach.call(root.querySelectorAll('input[type=submit], input[type=button]'), function (b) {
			var k = (b.value || '').trim();
			if (FA[k]) { b.value = FA[k]; }
		});
	}
	function start() {
		var html = document.documentElement;
		html.classList.add('samen-install');
		html.setAttribute('lang', 'fa');
		html.setAttribute('dir', 'rtl');
		document.title = 'راهنمای نصب | SamenCRM – سامانه مدیریت ارتباط با مشتری';
		Array.prototype.forEach.call(document.querySelectorAll('link[rel~="icon"]'), function (l) { l.parentNode.removeChild(l); });
		var icon = document.createElement('link');
		icon.rel = 'icon'; icon.type = 'image/png'; icon.href = BASE + 'img/favicon.png';
		document.head.appendChild(icon);

		var logo = document.querySelector('.page-container .logo img');
		if (logo) { logo.src = BASE + 'img/company-logo.png'; logo.alt = 'SamenCRM'; logo.width = 237; logo.height = 60; }
		var head = document.querySelector('.page-container .head');
		if (head && !head.querySelector('.samen-sub')) {
			var sub = document.createElement('p'); sub.className = 'samen-sub'; sub.textContent = 'سامانه مدیریت ارتباط با مشتری';
			head.appendChild(sub);
		}
		var art = document.querySelector('.welcome-image img');
		if (art) { art.src = BASE + 'img/cloud-art.webp'; art.alt = ''; art.width = 480; art.height = 398; }
		// help icon pointed to the vtiger 6 wiki; the stats pixel of the last step reported to vtiger.com
		Array.prototype.forEach.call(document.querySelectorAll('a[href*="vtiger.com"], img[src*="vtiger.com"]'), function (a) {
			a.parentNode.removeChild(a);
		});
		// SamenCRM installs in Persian unless the visitor picks another language
		var lang = document.getElementById('lang');
		if (lang && lang.querySelector('option[value="fa_ir"]') && !/[?&]lang=/.test(location.search)) { lang.value = 'fa_ir'; }
		// settings form: Iranian defaults instead of USD / mm-dd-yyyy / Los Angeles (only while untouched)
		var defaults = { currency_name: 'Iran, Rials', dateformat: 'yyyy-mm-dd', timezone: 'Asia/Tehran' };
		var stock = { currency_name: 'USA, Dollars', dateformat: 'mm-dd-yyyy', timezone: 'America/Los_Angeles' };
		Object.keys(defaults).forEach(function (name) {
			var s = document.querySelector('select[name="' + name + '"]');
			if (s && s.value === stock[name] && s.querySelector('option[value="' + defaults[name] + '"]')) {
				s.value = defaults[name];
				if (window.jQuery) { window.jQuery(s).trigger('change'); }
			}
		});
		tr(document.body);
		html.classList.add('samen-install-ready');   // lifts the no-flash guard set by the hook
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})(document);
