/*+**********************************************************************************
 * SamenTheme – small runtime helpers for the SamenStudent theme (HEADERSCRIPT link).
 *  - brand favicon
 *  - <html lang="fa" dir="rtl"> for Persian users (screen readers, native form controls)
 *  - Persian for the few labels Vtiger prints without vtranslate() (filter "All", widget titles...)
 ************************************************************************************/
(function (window, document) {
	'use strict';
	var BASE = 'layouts/v7/modules/SamenTheme/resources/';

	function favicon() {
		var head = document.head;
		Array.prototype.forEach.call(head.querySelectorAll('link[rel~="icon"]'), function (l) { l.parentNode.removeChild(l); });
		var link = document.createElement('link');
		link.rel = 'icon';
		link.type = 'image/png';
		link.href = BASE + 'img/favicon.png';
		head.appendChild(link);
	}

	// exact-text replacements for strings that Vtiger renders untranslated (data names / hard-coded labels)
	var FA = {
		'All': 'همه',
		'My Dashboard': 'داشبورد من',
		'Documents': 'اسناد',
		'Contacts Address': 'آدرس مخاطبین',
		'Todays Birthday': 'تولدهای امروز',
		'Prospect Accounts': 'سازمان‌های بالقوه',
		'Open Tickets': 'تیکت‌های باز',
		'Hot Leads': 'سرنخ‌های داغ',
		'Potentials Won': 'فرصت‌های موفق',
		'Open Quotes': 'پیش‌فاکتورهای باز',
		'Persian Calendar': 'تقویم شمسی',
		'Samen Theme': 'پوسته ثامن',
		'Calendar Settings': 'تنظیمات تقویم',
		'My Preferences': 'تنظیمات من',
		'Users': 'کاربران',
		'List': 'فهرست',
		'Lists': 'فهرست‌ها',
		'Primary Module': 'ماژول اصلی',
		'Default': 'پیش‌فرض',
		'Privacy Policy': 'حریم خصوصی'
	};
	// module names Vtiger concatenates into already-translated sentences ("بدون مورد مرتبط Documents")
	// (also field labels the calendar feed list joins with Persian module names: "Start Date & Time,End-رویدادها")
	var WORDS = { 'Documents': 'اسناد', 'Contacts': 'مخاطبین', 'Calendar': 'تقویم', 'Emails': 'ایمیل‌ها',
		'ModComments': 'یادداشت‌ها', 'Organizations': 'سازمان‌ها', 'Accounts': 'سازمان‌ها',
		'Start Date & Time': 'تاریخ و زمان شروع', 'End Date & Time': 'تاریخ و زمان پایان', 'Start Date': 'تاریخ شروع',
		'End Date': 'تاریخ پایان', 'Target End Date': 'تاریخ پایان هدف', 'Due Date': 'تاریخ سررسید', 'End': 'پایان', 'Due': 'سررسید', 'Date': 'تاریخ' };
	var WORD_KEYS = Object.keys(WORDS).sort(function (a, b) { return b.length - a.length; })
		.map(function (k) { return k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); });
	var WORD_RE = new RegExp('\\b(' + WORD_KEYS.join('|') + ')\\b', 'g');
	var HAS_FA = /[\u0600-\u06FF]/;
	var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, INPUT: 1, SELECT: 1, OPTION: 1 };
	function translateTree(root) {
		if (!root || root.nodeType !== 1) { return; }
		var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false), n, list = [];
		while ((n = w.nextNode())) { list.push(n); }
		list.forEach(function (t) {
			var key = t.nodeValue.trim();
			var exact = key && FA.hasOwnProperty(key), mixed = !exact && HAS_FA.test(key) && WORD_RE.test(key);
			WORD_RE.lastIndex = 0;
			if (!exact && !mixed) { return; }
			for (var p = t.parentNode; p && p.nodeType === 1; p = p.parentNode) {
				if (SKIP[p.tagName] || p.isContentEditable) { return; }
			}
			t.nodeValue = exact ? t.nodeValue.replace(key, FA[key]) : t.nodeValue.replace(WORD_RE, function (w) { return WORDS[w]; });
		});
	}

	function start() {
		favicon();
		var fa = /^fa/i.test(document.body.getAttribute('data-language') || '');
		document.documentElement.classList.add('samen-theme');
		if (!fa) { return; }
		document.documentElement.setAttribute('lang', 'fa');
		document.documentElement.setAttribute('dir', 'rtl');
		translateTree(document.body);
		var queue = [], busy = false;
		new MutationObserver(function (m) {
			m.forEach(function (r) { Array.prototype.forEach.call(r.addedNodes, function (x) { queue.push(x.nodeType === 3 ? x.parentNode : x); }); });
			if (busy) { return; }
			busy = true;
			(window.requestAnimationFrame || setTimeout)(function () { busy = false; var q = queue; queue = []; q.forEach(translateTree); });
		}).observe(document.body, { childList: true, subtree: true });
	}

	window.SamenTheme = { translations: FA, translate: translateTree };
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})(window, document);
