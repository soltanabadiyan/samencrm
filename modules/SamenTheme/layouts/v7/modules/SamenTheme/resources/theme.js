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
		'Samen Theme': 'پوسته SamenCRM', 'SamenCRM Theme': 'پوسته SamenCRM',
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
		'Webmails': 'وب‌میل', 'Webforms': 'فرم‌های وب', 'RSS': 'خوراک RSS', 'Google': 'گوگل', 'Default Skin': 'پوسته پیش‌فرض',
		// found by tools/ui_sweep.mjs: labels Vtiger prints with a module whose language file lacks them,
		// and sample data (tax / charge names, currency) shown as text
		'Template Name': 'نام قالب', 'Module Name': 'نام ماژول', 'Recurring Invoice Information': 'اطلاعات فاکتور تکراری',
		'Iran, Rials (﷼)': 'ریال ایران (﷼)', 'Iran, Rials': 'ریال ایران', 'Discount': 'تخفیف', 'Shipping & Handling': 'هزینه ارسال و جابجایی',
		'VAT': 'مالیات بر ارزش افزوده', 'Sales': 'فروش', 'Service': 'خدمات',
		'Shipping & Handling - VAT': 'هزینه ارسال - مالیات بر ارزش افزوده', 'Shipping & Handling - Sales': 'هزینه ارسال - فروش',
		'Shipping & Handling - Service': 'هزینه ارسال - خدمات', 'Save': 'ذخیره', 'Edit': 'ویرایش',
		'Call Status': 'وضعیت تماس', 'Customer Number': 'شماره مشتری', 'Customer': 'مشتری', 'User': 'کاربر', 'Start Time': 'زمان شروع',
		'Sales Stage': 'مرحله فروش', 'Amount': 'مبلغ', 'Proposal or Price Quote': 'پیشنهاد یا پیش‌فاکتور', 'Negotiation or Review': 'مذاکره یا بررسی',
		'Due date': 'تاریخ سررسید', 'Send Reminder': 'ارسال یادآوری',
		'Public: Read Only': 'عمومی: فقط خواندن', 'Public: Read, Create/Edit': 'عمومی: خواندن، ایجاد و ویرایش',
		'Public: Read, Create/Edit, Delete': 'عمومی: خواندن، ایجاد، ویرایش و حذف', 'Private': 'خصوصی', 'Comments': 'یادداشت‌ها',
		'Frequency(H:M)': 'تناوب (ساعت:دقیقه)', 'Enable': 'فعال', 'Currency Name': 'نام ارز', 'Currency Code': 'کد ارز', 'Symbol': 'نماد',
		'Source Field': 'فیلد منبع', 'Target Field': 'فیلد مقصد', 'MenuEditor': 'ویرایشگر منو', 'Fixed': 'ثابت', 'Simple': 'ساده',
		'Private/Public': 'خصوصی/عمومی', 'Info': 'راهنما',
		'Relation': 'ارتباط', 'Url': 'نشانی وب', 'WEBSERVICE': 'وب‌سرویس', 'Login to marketplace': 'ورود به فروشگاه افزونه‌ها',
		// e-mail template NAMES: code looks them up by these English names, so they are only translated on screen
		// (subjects / descriptions / system bodies are translated in the database by i18n/translate_workflows.php)
		'Announcement for Release': 'اطلاعیه انتشار', 'Pending Invoices': 'فاکتورهای پرداخت‌نشده', 'Acceptance Proposal': 'پذیرش پیشنهاد',
		'Goods received acknowledgement': 'تأیید دریافت کالا', 'Accept Order': 'پذیرش سفارش', 'Address Change': 'تغییر نشانی',
		'Follow Up': 'پیگیری', 'Target Crossed!': 'هدف محقق شد!', 'Thanks Note': 'یادداشت سپاس', 'Customer Login Details': 'اطلاعات ورود مشتری',
		'Support end notification before a week': 'اطلاعیه پایان پشتیبانی (یک هفته مانده)',
		'Support end notification before a month': 'اطلاعیه پایان پشتیبانی (یک ماه مانده)',
		'Activity Reminder': 'یادآوری رویداد', 'ToDo Reminder': 'یادآوری وظیفه', 'Invite Users': 'دعوت کاربران',
		// scheduler task names (Settings > Scheduler): referenced by name in code, translated on screen only
		'Workflow': 'گردش‌کار', 'RecurringInvoice': 'فاکتورهای تکراری', 'SendReminder': 'ارسال یادآوری‌ها', 'MailScanner': 'اسکنر ایمیل',
		'Scheduled Import': 'ورود اطلاعات زمان‌بندی‌شده', 'ScheduleReports': 'گزارش‌های زمان‌بندی‌شده', 'Oauth2TokenRefresher': 'نوسازی توکن OAuth2',
		'TelegramBot Worker': 'تلگرام: صف و دریافت پیام‌ها', 'TelegramBot Birthdays': 'تلگرام: پیام‌های تولد',
		// calendar settings / preferences values
		'Today': 'امروز', 'This Week': 'این هفته', 'This Month': 'این ماه', 'Planned': 'برنامه‌ریزی‌شده', 'Held': 'برگزارشده',
		'Not Held': 'برگزارنشده', 'Call': 'تماس', 'Meeting': 'جلسه', 'Minute': 'دقیقه', 'Minutes': 'دقیقه', 'Hours': 'ساعت',
		'User : All': 'کاربر: همه', 'Access Count': 'تعداد مشاهده', 'Click Count': 'تعداد کلیک',
		'Saturday': 'شنبه', 'Sunday': 'یکشنبه', 'Monday': 'دوشنبه', 'Tuesday': 'سه‌شنبه', 'Wednesday': 'چهارشنبه', 'Thursday': 'پنجشنبه', 'Friday': 'جمعه'
	};
	// placeholders / titles Vtiger prints in English
	var ATTR = { 'Search for List': 'جستجوی فهرست', 'Search for Folders': 'جستجوی پوشه‌ها', 'Search': 'جستجو',
		'Type to search': 'برای جستجو تایپ کنید', 'Search Settings': 'جستجوی تنظیمات', 'Enter tag name': 'نام برچسب را وارد کنید',
		'Search for an extension..': 'جستجوی افزونه...', 'Search for an extension': 'جستجوی افزونه' };
	// module names Vtiger concatenates into already-translated sentences ("بدون مورد مرتبط Documents")
	// (also field labels the calendar feed list joins with Persian module names: "Start Date & Time,End-رویدادها")
	var WORDS = { 'Documents': 'اسناد', 'Contacts': 'مخاطبین', 'Calendar': 'تقویم', 'Emails': 'ایمیل‌ها',
		'ModComments': 'یادداشت‌ها', 'Organizations': 'سازمان‌ها', 'Accounts': 'سازمان‌ها',
		'Start Date & Time': 'تاریخ و زمان شروع', 'End Date & Time': 'تاریخ و زمان پایان', 'Start Date': 'تاریخ شروع',
		'End Date': 'تاریخ پایان', 'Target End Date': 'تاریخ پایان هدف', 'Due Date': 'تاریخ سررسید', 'End': 'پایان', 'Due': 'سررسید', 'Date': 'تاریخ', 'Faq': 'پرسش‌های متداول', 'Comment': 'یادداشت' };
	var WORD_KEYS = Object.keys(WORDS).sort(function (a, b) { return b.length - a.length; })
		.map(function (k) { return k.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); });
	var WORD_RE = new RegExp('\\b(' + WORD_KEYS.join('|') + ')\\b', 'g');
	var HAS_FA = /[\u0600-\u06FF]/;
	// LBL_NO is one key for the answer "No" (\u062E\u06CC\u0631) and for "No \u2026 found" \u2013 fix the glued sentences on screen
	var SENTENCES = [
		[/\u062E\u06CC\u0631\s+(.+?)\s+\u0628\u0627 \u0627\u06CC\u0646 \u0645\u0639\u06CC\u0627\u0631 \u0645\u0637\u0627\u0628\u0642\u062A \u062F\u0627\u0634\u062A\.?/g, '\u0647\u06CC\u0686 \u0645\u0648\u0631\u062F\u06CC \u062F\u0631 \u00AB$1\u00BB \u0628\u0627 \u0627\u06CC\u0646 \u0634\u0631\u0627\u06CC\u0637 \u06CC\u0627\u0641\u062A \u0646\u0634\u062F.'],
		[/\u062E\u06CC\u0631\s+(.+?)\s+\u06CC\u0627\u0641\u062A \u0634\u062F\.?/g, '\u0647\u06CC\u0686 \u0645\u0648\u0631\u062F\u06CC \u062F\u0631 \u00AB$1\u00BB \u06CC\u0627\u0641\u062A \u0646\u0634\u062F.']
	];
	var SKIP = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, INPUT: 1, SELECT: 1, OPTION: 1 };
	// Task Management panel prints priorities / statuses as raw picklist values (only translated inside the panel)
	var TASKS = { 'TASK MANAGEMENT': 'مدیریت وظایف', 'High': 'اولویت زیاد', 'Medium': 'اولویت متوسط', 'Low': 'اولویت کم',
		'Not Started': 'شروع نشده', 'In Progress': 'در حال انجام', 'Completed': 'تکمیل‌شده', 'Deferred': 'معوق',
		'Pending Input': 'منتظر ورودی', 'Planned': 'برنامه‌ریزی‌شده', 'Held': 'برگزارشده', 'Not Held': 'برگزارنشده' };
	ATTR['User : All'] = 'کاربر: همه';
	ATTR['Search for an extension..'] = 'جستجوی افزونه...';
	function translateAttrs(root) {
		var els = [root].concat(Array.prototype.slice.call(root.querySelectorAll('[placeholder], [title], [data-original-title]')));
		els.forEach(function (e) {
			['placeholder', 'title', 'data-original-title'].forEach(function (a) {
				var v = e.getAttribute && e.getAttribute(a);
				if (v && ATTR.hasOwnProperty(v.trim())) { e.setAttribute(a, ATTR[v.trim()]); }
			});
		});
	}
	// report tables: Vtiger prefixes each column with its module in English word order ("مخاطبین نام") – show the
	// field, and "صنعت (سازمان‌ها)" for fields of a secondary module (same rule as the print page)
	var REPORT_MODS = ['مخاطبین', 'سازمان‌ها', 'سرنخ‌ها', 'فرصت‌ها', 'تیکت‌ها', 'کمپین‌ها', 'تقویم', 'رویدادها', 'اسناد', 'محصولات', 'خدمات',
		'پیش‌فاکتورها', 'فاکتورها', 'سفارش‌های فروش', 'سفارش‌های خرید', 'تأمین‌کنندگان', 'پروژه‌ها', 'وظایف پروژه', 'دارایی‌ها', 'کاربران'];
	function reportHeaders(root) {
		if (!/module=Reports/.test(window.location.search) || !root.querySelectorAll) { return; }
		var ths = Array.prototype.slice.call(root.querySelectorAll('table th')).filter(function (th) { return !th.getAttribute('data-samen-h'); });
		if (!ths.length) { return; }
		var parts = ths.map(function (th) {
			var t = (th.textContent || '').trim(), mod = '';
			REPORT_MODS.forEach(function (m) { if (!mod && t.indexOf(m + ' ') === 0) { mod = m; } });
			return { th: th, mod: mod, field: mod ? t.slice(mod.length + 1) : '' };
		});
		var cnt = {}; parts.forEach(function (x) { if (x.mod) { cnt[x.mod] = (cnt[x.mod] || 0) + 1; } });
		var primary = Object.keys(cnt).sort(function (a, b) { return cnt[b] - cnt[a]; })[0];
		parts.forEach(function (x) {
			if (!x.mod || x.th.querySelector('input, select, a')) { return; }
			x.th.setAttribute('data-samen-h', '1');
			x.th.textContent = x.mod === primary ? x.field : x.field + ' (' + x.mod + ')';
		});
	}
	function translateTree(root) {
		if (!root || root.nodeType !== 1) { return; }
		translateAttrs(root);
		reportHeaders(root);
		var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false), n, list = [];
		while ((n = w.nextNode())) { list.push(n); }
		list.forEach(function (t) {
			var key = t.nodeValue.trim();
			if (TASKS.hasOwnProperty(key) && t.parentNode && t.parentNode.closest && t.parentNode.closest('#taskManagementContainer, .overlayHeader')) {
				t.nodeValue = t.nodeValue.replace(key, TASKS[key]); return;
			}
			// sentences Vtiger glues from fragments in English word order: "{No} {Module} {found}" etc.
			if (key.indexOf('خیر ') > -1) {
				var s = t.nodeValue;
				SENTENCES.forEach(function (r) { s = s.replace(r[0], r[1]); });
				if (s !== t.nodeValue) { t.nodeValue = s; return; }
			}
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

	// Vtiger wires the app menu / search / quick create through a page controller <Module>_<view>_Js
	// (application.js getModuleSpecificViewClass). Some stock pages have none or a plain object
	// (Import: "Vtiger_Import_Js is not a constructor"), which leaves the menu dead – give them an empty one.
	function pageController() {
		if (typeof window.Vtiger_Index_Js !== 'function') { return; }
		var q = new URLSearchParams(window.location.search), module = q.get('module'), view = q.get('view');
		if (!module || !view || q.get('parent') === 'Settings' || !/^\w+$/.test(module + view)) { return; }
		var own = module + '_' + view + '_Js', generic = 'Vtiger_' + view + '_Js';
		if (typeof window[own] === 'function') { return; }
		if (typeof window[own] === 'undefined' && typeof window[generic] === 'function') { return; }
		if (typeof window[own] === 'undefined') { window.Vtiger_Index_Js(own, {}, {}); }
	}
	pageController();

	// phones: when the fixed header wraps to two rows (e.g. Calendar's add buttons) it covers the top of
	// the page (the calendar's prev/next/view buttons) – push the page down by the overlap
	function navOffset() {
		var nav = document.querySelector('.app-fixed-navbar'), main = document.querySelector('.main-container');
		if (!nav || !main) { return; }
		main.style.marginTop = '';
		var gap = nav.getBoundingClientRect().bottom - main.getBoundingClientRect().top;
		if (gap > 8) { main.style.marginTop = Math.ceil(gap) + 'px'; }
	}

	function start() {
		favicon();
		navOffset();
		window.addEventListener('resize', navOffset);
		if (window.ResizeObserver && document.querySelector('.app-fixed-navbar')) {
			new ResizeObserver(navOffset).observe(document.querySelector('.app-fixed-navbar'));
		}
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
