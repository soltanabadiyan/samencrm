/*+**********************************************************************************
 * SamenTheme – small runtime helpers for the SamenCRM theme (HEADERSCRIPT link).
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
		'Privacy Policy': 'حریم خصوصی',
		// Vtiger's sample filters – core code looks some of them up by English name, so they are only translated on screen
		'This Month Leads': 'سرنخ‌های این ماه', 'New This Week': 'جدید در این هفته', 'Prospecting': 'در حال جستجو',
		'High Prioriy Tickets': 'تیکت‌های با اولویت بالا', 'Rejected Quotes': 'پیش‌فاکتورهای ردشده',
		'Drafted FAQ': 'پرسش‌های متداول پیش‌نویس', 'Published FAQ': 'پرسش‌های متداول منتشرشده',
		'Open Purchase Orders': 'سفارش‌های خرید باز', 'Received Purchase Orders': 'سفارش‌های خرید دریافت‌شده',
		'Open Invoices': 'فاکتورهای باز', 'Paid Invoices': 'فاکتورهای پرداخت‌شده', 'Pending Sales Orders': 'سفارش‌های فروش در انتظار',
		'CRM Phone Extension': 'داخلی تلفن CRM', 'Default Landing Page': 'صفحه پیش‌فرض پس از ورود', 'Picklist': 'لیست انتخابی',
		'Datetime': 'تاریخ و زمان', 'DocumentsFolder': 'پوشه اسناد', 'Owner': 'مالک', 'FileLocationType': 'نوع محل فایل',
		'DocumentsFileUpload': 'بارگذاری فایل سند', 'Module': 'ماژول', 'Workflow Name': 'نام گردش‌کار', 'Trigger': 'محرک',
		'Conditions': 'شرایط', 'Groups': 'گروه‌ها', 'Name': 'نام', 'Select from My Computer': 'انتخاب از رایانه من',
		// (bootstrap-switch ON/OFF stay English: the widget measures its handles from that text at init)
		'comma': 'ویرگول', 'semicolon': 'نقطه‌ویرگول', 'Pipe': 'خط عمودی', 'Caret': 'هشتک (^)',
		'Webmails': 'وب‌میل', 'Webforms': 'فرم‌های وب', 'RSS': 'خوراک RSS', 'Google': 'گوگل', 'Default Skin': 'پوسته پیش‌فرض'
	};
	// placeholders / titles Vtiger prints in English
	var ATTR = { 'Search for List': 'جستجوی فهرست', 'Search for Folders': 'جستجوی پوشه‌ها', 'Search': 'جستجو',
		'Type to search': 'برای جستجو تایپ کنید', 'Search Settings': 'جستجوی تنظیمات', 'Enter tag name': 'نام برچسب را وارد کنید' };
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

	function brandTitle() {
		var t = document.title || '';
		if (!/SamenCRM/.test(t)) { document.title = (t.replace(/\s*[-|]?\s*vtiger( CRM)?/i, '').trim() || 'SamenCRM') + ' | SamenCRM'; }
	}

	// phones: start list pages with the filter sidebar collapsed so the records are visible first
	// (same classes Vtiger's own .essentials-toggle switches, so the toggle still opens it)
	function mobileSidebar() {
		if (!window.matchMedia || !window.matchMedia('(max-width: 767px)').matches) { return; }
		var side = document.querySelector('.sidebar-essentials');
		if (!side || side.classList.contains('hide')) { return; }
		side.classList.add('hide');
		Array.prototype.forEach.call(document.querySelectorAll('.content-area'), function (c) { c.classList.add('full-width'); });
	}

	function start() {
		favicon();
		brandTitle();
		mobileSidebar();
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
