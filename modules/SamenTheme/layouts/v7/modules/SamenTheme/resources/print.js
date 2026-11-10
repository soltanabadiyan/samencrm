/* SamenTheme – report print: SamenCRM header / footer, RTL, Jalali dates (PrintReport.tpl hook). */
(function (document) {
	'use strict';
	// Vtiger's print code still reads jQuery.browser (removed in jQuery 1.9) to repeat table headers per page
	if (window.jQuery && !window.jQuery.browser) { window.jQuery.browser = { webkit: /AppleWebKit/.test(navigator.userAgent) }; }
	var BASE = 'layouts/v7/modules/SamenTheme/resources/';
	function fa(d) { try { return new Intl.DateTimeFormat('fa-IR-u-ca-persian', { year: 'numeric', month: '2-digit', day: '2-digit' }).format(d); } catch (e) { return d.toISOString().slice(0, 10); } }
	function faTime(d) { try { return new Intl.DateTimeFormat('fa-IR', { hour: '2-digit', minute: '2-digit', hour12: false }).format(d); } catch (e) { return ''; } }
	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }
	function start() {
		var html = document.documentElement;
		html.setAttribute('dir', 'rtl'); html.setAttribute('lang', 'fa');
		var head = document.querySelector('.reportPrintHeader');
		var name = head ? (head.querySelector('h2') || {}).textContent || '' : '';
		var count = head ? ((head.querySelector('h3') || {}).textContent || '').trim() : '';
		var now = new Date();
		document.title = (name ? name + ' – ' : '') + 'گزارش SamenCRM';

		var outer = document.querySelector('body > table');
		var sheet = el('div', 'sp-sheet');
		var top = el('header', 'sp-head');
		var logo = el('img'); logo.src = BASE + 'img/company-logo.png'; logo.alt = 'SamenCRM';
		// the logo already carries the SamenCRM wordmark and subtitle
		var brand = el('div', 'sp-brand'); brand.appendChild(el('small', null, 'SamenCloud Co. · samencloud.ir'));
		var title = el('div', 'sp-title'); title.appendChild(el('h1', null, name || 'گزارش'));
		var meta = el('div', 'sp-meta');
		[['تعداد رکورد', count.replace(/[^\d۰-۹]/g, '') || '—'], ['تاریخ چاپ', fa(now) + ' – ' + faTime(now)]].forEach(function (m) {
			var s = el('span', null, m[0] + ': '); s.appendChild(el('b', null, m[1])); meta.appendChild(s);
		});
		title.appendChild(meta);
		top.appendChild(logo); top.appendChild(brand); top.appendChild(title);
		var foot = el('footer', 'sp-foot');
		var l = el('span'); l.appendChild(el('b', null, 'SamenCRM')); l.appendChild(document.createTextNode(' · https://samencloud.ir'));
		foot.appendChild(l); foot.appendChild(el('span', null, 'این گزارش به‌صورت خودکار از SamenCRM تهیه شده است'));
		if (outer) {
			outer.parentNode.insertBefore(sheet, outer);
			sheet.appendChild(top); sheet.appendChild(outer); sheet.appendChild(foot);
		}
		var actions = el('div', 'sp-actions');
		var p = el('button', null, 'چاپ'); p.type = 'button'; p.onclick = function () { window.print(); };
		var c = el('button', 'alt', 'بستن'); c.type = 'button'; c.onclick = function () { window.close(); };
		actions.appendChild(c); actions.appendChild(p);
		document.body.insertBefore(actions, document.body.firstChild);

		// column titles: Vtiger prefixes each field with its module ("مخاطبین نام") – show "نام", and "صنعت (سازمان‌ها)"
		// for fields of a secondary module
		var MODS = ['مخاطبین', 'سازمان‌ها', 'سرنخ‌ها', 'فرصت‌ها', 'تیکت‌ها', 'کمپین‌ها', 'تقویم', 'رویدادها', 'اسناد', 'محصولات', 'خدمات',
			'پیش‌فاکتورها', 'فاکتورها', 'سفارش‌های فروش', 'سفارش‌های خرید', 'تأمین‌کنندگان', 'پروژه‌ها', 'وظایف پروژه', 'دارایی‌ها', 'کاربران'];
		var ths = Array.prototype.slice.call(document.querySelectorAll('.printReport thead th'));
		var split = ths.map(function (th) {
			var t = (th.textContent || '').trim(), mod = '';
			MODS.forEach(function (m) { if (!mod && t.indexOf(m + ' ') === 0) { mod = m; } });
			return { th: th, mod: mod, field: mod ? t.slice(mod.length + 1) : t };
		});
		var count2 = {}; split.forEach(function (x) { if (x.mod) { count2[x.mod] = (count2[x.mod] || 0) + 1; } });
		var primary = Object.keys(count2).sort(function (a, b) { return count2[b] - count2[a]; })[0];
		split.forEach(function (x) { if (x.mod) { x.th.textContent = x.mod === primary ? x.field : x.field + ' (' + x.mod + ')'; } });

		// Gregorian dates in the data -> Jalali; numbers / codes kept left-to-right
		Array.prototype.forEach.call(document.querySelectorAll('.printReport td'), function (td) {
			var t = (td.textContent || '').trim();
			var m = /^(\d{4})-(\d{2})-(\d{2})(?:\s+(\d{1,2}:\d{2}(?::\d{2})?(?:\s*[AP]M)?))?$/i.exec(t);
			if (m) { td.textContent = fa(new Date(+m[1], +m[2] - 1, +m[3])) + (m[4] ? ' ' + m[4] : ''); return; }
			if (/^[+\-]?[\d.,\s]+$/.test(t) || /^[A-Za-z0-9@._+\-\/:]+$/.test(t)) { td.classList.add('sp-num'); }
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})(document);
