/*+**********************************************************************************
 * PersianCalendar – Jalali (Shamsi) dates for Vtiger CRM 7/8 (v7 layout)
 * SamenStudent. Loaded on every page as a HEADERSCRIPT link (see PersianCalendar.php).
 *
 * Active only for users whose language is fa_ir (body[data-language]).
 *  - Dates are STORED in Gregorian (Vtiger standard); only the UI is Jalali:
 *      * every displayed date in the user's date format is rewritten to yyyy/mm/dd Jalali
 *        (original kept in the element's title), incl. AJAX-loaded content (MutationObserver)
 *      * every input.dateField gets a Jalali twin input + Jalali date picker; the original
 *        (hidden) input keeps the Gregorian value that Vtiger validates and saves
 *      * the Calendar module (FullCalendar 3) shows Jalali titles / day numbers, RTL, week from Saturday
 *  - window.PersianCalendar exposes the converters (toJalali / toGregorian / format / parse).
 * Conversion algorithm: jalaali-js (MIT, Behrang Noruzi Niya), re-implemented.
 ************************************************************************************/
(function (window, document) {
	'use strict';

	/* ------------------------------------------------------------------ conversion */
	function div(a, b) { return ~~(a / b); }
	function mod(a, b) { return a - ~~(a / b) * b; }
	var BREAKS = [-61, 9, 38, 199, 426, 686, 756, 818, 1111, 1181, 1210, 1635, 2060, 2097, 2192, 2262, 2324, 2394, 2456, 3178];

	function jalCal(jy) {
		var bl = BREAKS.length, gy = jy + 621, leapJ = -14, jp = BREAKS[0], jm, jump = 0, leap, leapG, march, n, i;
		if (jy < jp || jy >= BREAKS[bl - 1]) { throw new Error('Invalid Jalali year ' + jy); }
		for (i = 1; i < bl; i += 1) {
			jm = BREAKS[i]; jump = jm - jp;
			if (jy < jm) { break; }
			leapJ = leapJ + div(jump, 33) * 8 + div(mod(jump, 33), 4);
			jp = jm;
		}
		n = jy - jp;
		leapJ = leapJ + div(n, 33) * 8 + div(mod(n, 33) + 3, 4);
		if (mod(jump, 33) === 4 && jump - n === 4) { leapJ += 1; }
		leapG = div(gy, 4) - div((div(gy, 100) + 1) * 3, 4) - 150;
		march = 20 + leapJ - leapG;
		if (jump - n < 6) { n = n - jump + div(jump + 4, 33) * 33; }
		leap = mod(mod(n + 1, 33) - 1, 4);
		if (leap === -1) { leap = 4; }
		return { leap: leap, gy: gy, march: march };
	}
	function g2d(gy, gm, gd) {
		var d = div((gy + div(gm - 8, 6) + 100100) * 1461, 4) + div(153 * mod(gm + 9, 12) + 2, 5) + gd - 34840408;
		return d - div(div(gy + 100100 + div(gm - 8, 6), 100) * 3, 4) + 752;
	}
	function d2g(jdn) {
		var j = 4 * jdn + 139361631, i, gd, gm, gy;
		j = j + div(div(4 * jdn + 183187720, 146097) * 3, 4) * 4 - 3908;
		i = div(mod(j, 1461), 4) * 5 + 308;
		gd = div(mod(i, 153), 5) + 1;
		gm = mod(div(i, 153), 12) + 1;
		gy = div(j, 1461) - 100100 + div(8 - gm, 6);
		return { gy: gy, gm: gm, gd: gd };
	}
	function j2d(jy, jm, jd) {
		var r = jalCal(jy);
		return g2d(r.gy, 3, r.march) + (jm - 1) * 31 - div(jm, 7) * (jm - 7) + jd - 1;
	}
	function d2j(jdn) {
		var gy = d2g(jdn).gy, jy = gy - 621, r = jalCal(jy), jdn1f = g2d(gy, 3, r.march), k = jdn - jdn1f;
		if (k >= 0) {
			if (k <= 185) { return { jy: jy, jm: 1 + div(k, 31), jd: mod(k, 31) + 1 }; }
			k -= 186;
		} else {
			jy -= 1; k += 179;
			if (r.leap === 1) { k += 1; }
		}
		return { jy: jy, jm: 7 + div(k, 30), jd: mod(k, 30) + 1 };
	}

	var PC = {
		MONTHS: ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'],
		// index = JS getDay() (0 = Sunday)
		WEEKDAYS: ['یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه', 'شنبه'],
		WEEKDAYS_SHORT: ['ی', 'د', 'س', 'چ', 'پ', 'ج', 'ش'],
		toJalali: function (gy, gm, gd) { return d2j(g2d(gy, gm, gd)); },
		toGregorian: function (jy, jm, jd) { return d2g(j2d(jy, jm, jd)); },
		isLeap: function (jy) { return jalCal(jy).leap === 0; },
		monthLength: function (jy, jm) { return jm <= 6 ? 31 : (jm <= 11 ? 30 : (PC.isLeap(jy) ? 30 : 29)); },
		pad: function (n) { return (n < 10 ? '0' : '') + n; },
		/** Jalali yyyy/mm/dd from a Gregorian {gy,gm,gd} */
		formatJ: function (j) { return j.jy + '/' + PC.pad(j.jm) + '/' + PC.pad(j.jd); },
		/** ISO yyyy-mm-dd from {gy,gm,gd} */
		formatISO: function (g) { return g.gy + '-' + PC.pad(g.gm) + '-' + PC.pad(g.gd); },
		faDigits: function (s) { return String(s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.charAt(+d); }); },
		enDigits: function (s) {
			return String(s).replace(/[۰-۹]/g, function (d) { return d.charCodeAt(0) - 1776; })
				.replace(/[٠-٩]/g, function (d) { return d.charCodeAt(0) - 1632; });
		},
		/** parse a Jalali string typed by the user -> {jy,jm,jd} or null */
		parseJ: function (s) {
			var m = PC.enDigits(s || '').trim().match(/^(1[2-5]\d\d)[\/\-.](\d{1,2})[\/\-.](\d{1,2})$/);
			if (!m) { return null; }
			var j = { jy: +m[1], jm: +m[2], jd: +m[3] };
			if (j.jm < 1 || j.jm > 12 || j.jd < 1 || j.jd > PC.monthLength(j.jy, j.jm)) { return null; }
			return j;
		}
	};

	/* ---------------------------------------------- user date format (Gregorian, as Vtiger renders it) */
	var fmt = null;       // {re: RegExp(global), order: ['y','m','d'], sep, build(g)}
	function setupFormat(userFormat) {
		userFormat = (userFormat || 'yyyy-mm-dd').toLowerCase();
		var sep = (userFormat.match(/[^a-z]/) || ['-'])[0];
		var parts = userFormat.split(sep), order = [], src = [];
		parts.forEach(function (p) {
			if (p.indexOf('y') === 0) { order.push('y'); src.push('((?:19|20)\\d\\d)'); }
			else if (p.indexOf('m') === 0) { order.push('m'); src.push('(0[1-9]|1[0-2])'); }
			else { order.push('d'); src.push('(0[1-9]|[12]\\d|3[01])'); }
		});
		var esc = sep.replace(/[.*+?^${}()|[\]\\\/]/g, '\\$&');
		fmt = {
			sep: sep, order: order,
			// no digit / slash directly around the match (avoids eating parts of ids, urls, phone numbers)
			re: new RegExp('(^|[^\\d\\/])' + src.join(esc) + '(?![\\d])', 'g'),
			build: function (g) {
				var v = { y: String(g.gy), m: PC.pad(g.gm), d: PC.pad(g.gd) };
				return order.map(function (k) { return v[k]; }).join(sep);
			},
			parse: function (s) {
				var m = new RegExp('^' + src.join(esc) + '$').exec((s || '').trim());
				if (!m) { return null; }
				var g = {};
				order.forEach(function (k, i) { g[k] = +m[i + 1]; });
				return { gy: g.y, gm: g.m, gd: g.d };
			}
		};
	}
	PC.gregorianToDisplay = function (s) {      // "2026-11-10" (user format) -> "1405/08/19"
		var g = fmt.parse(s); return g ? PC.formatJ(PC.toJalali(g.gy, g.gm, g.gd)) : s;
	};

	/* ------------------------------------------------------------------ display rewriting */
	var SKIP_TAGS = { SCRIPT: 1, STYLE: 1, TEXTAREA: 1, INPUT: 1, SELECT: 1, OPTION: 1, NOSCRIPT: 1, IFRAME: 1, CODE: 1, PRE: 1 };
	function convertText(text) {
		fmt.re.lastIndex = 0;
		if (!fmt.re.test(text)) { return null; }
		fmt.re.lastIndex = 0;
		return text.replace(fmt.re, function () {
			var a = arguments, lead = a[1], vals = {};
			fmt.order.forEach(function (k, i) { vals[k] = +a[i + 2]; });
			try { return lead + PC.formatJ(PC.toJalali(vals.y, vals.m, vals.d)); } catch (e) { return a[0]; }
		});
	}
	function skipNode(el) {
		for (; el && el.nodeType === 1; el = el.parentNode) {
			if (SKIP_TAGS[el.tagName] || el.isContentEditable || (el.classList && (el.classList.contains('pc-skip') || el.classList.contains('cke')))) { return true; }
		}
		return false;
	}
	function convertTree(root) {
		if (!root || (root.nodeType !== 1 && root.nodeType !== 9 && root.nodeType !== 11)) {
			if (root && root.nodeType === 3) { convertTextNode(root); }
			return;
		}
		if (root.nodeType === 1 && skipNode(root)) { return; }
		var w = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, null, false), n, list = [];
		while ((n = w.nextNode())) { list.push(n); }
		list.forEach(convertTextNode);
	}
	function convertTextNode(n) {
		if (!n.nodeValue || n.nodeValue.length < 8 || skipNode(n.parentNode)) { return; }
		var out = convertText(n.nodeValue);
		if (out !== null && out !== n.nodeValue) {
			var p = n.parentNode;
			if (p && !p.getAttribute('title')) { p.setAttribute('title', n.nodeValue.trim()); }
			n.nodeValue = out;
		}
	}

	/* ------------------------------------------------------------------ date inputs */
	var $ = window.jQuery, picker = null;

	function setOrig(orig, value) {
		orig._pcSetting = true;
		orig.value = value;
		orig._pcSetting = false;
		$(orig).trigger('change').trigger('keyup');
	}
	function syncProxy(orig) {
		var proxy = orig._pcProxy;
		if (!proxy) { return; }
		var v = orig.value || '';
		if (orig.getAttribute('data-calendar-type') === 'range') {
			proxy.value = v ? v.split(',').map(function (x) { return PC.gregorianToDisplay(x.trim()); }).join(' - ') : '';
		} else {
			proxy.value = v ? PC.gregorianToDisplay(v) : '';
		}
	}
	function proxyChanged(orig) {
		var proxy = orig._pcProxy, raw = PC.enDigits(proxy.value).trim(), range = orig.getAttribute('data-calendar-type') === 'range';
		if (!raw) { setOrig(orig, ''); return; }
		var parts = range ? raw.split(/\s*(?:-|,|تا)\s*(?=1[2-5]\d\d)/) : [raw], out = [];
		for (var i = 0; i < parts.length; i++) {
			var j = PC.parseJ(parts[i]);
			if (!j) { proxy.classList.add('pc-invalid'); return; }
			var g = PC.toGregorian(j.jy, j.jm, j.jd);
			out.push(fmt.build(g));
		}
		proxy.classList.remove('pc-invalid');
		setOrig(orig, out.join(','));
		syncProxy(orig);
	}

	function enhanceDateInput(orig) {
		if (orig._pcProxy || orig.classList.contains('pc-orig') || orig.type === 'hidden') { return; }
		var $o = $(orig);
		try { if ($o.data('datepicker')) { $o.datepicker('remove'); } } catch (e) { /* ignore */ }
		try { if ($o.data('dateRangePicker')) { $o.data('dateRangePicker').destroy(); } } catch (e2) { /* ignore */ }
		var proxy = document.createElement('input');
		proxy.type = 'text';
		proxy.className = (orig.className.replace(/\bdateField\b/g, '') + ' pc-jalali-input').trim();
		proxy.setAttribute('autocomplete', 'off');
		proxy.setAttribute('dir', 'ltr');
		proxy.placeholder = orig.getAttribute('data-calendar-type') === 'range' ? '۱۴۰۴/۰۱/۰۱ - ۱۴۰۴/۰۱/۳۱' : '۱۴۰۴/۰۱/۰۱';
		if (orig.disabled) { proxy.disabled = true; }
		if (orig.readOnly) { proxy.readOnly = true; }
		if (orig.style.width) { proxy.style.width = orig.style.width; }
		orig.parentNode.insertBefore(proxy, orig.nextSibling);
		orig.classList.add('pc-orig');
		orig.setAttribute('tabindex', '-1');
		orig._pcProxy = proxy;
		proxy._pcOrig = orig;
		syncProxy(orig);
		proxy.addEventListener('change', function () { proxyChanged(orig); });
		proxy.addEventListener('keydown', function (e) {
			if (e.key === 'Enter') { proxyChanged(orig); closePicker(); }
			if (e.key === 'Escape') { closePicker(); }
		});
		proxy.addEventListener('focus', function () { openPicker(orig); });
		proxy.addEventListener('click', function () { openPicker(orig); });
		orig.addEventListener('change', function () { if (!orig._pcSetting) { syncProxy(orig); } });
		var addon = orig.parentNode && orig.parentNode.querySelector('.input-group-addon');
		if (addon) {
			$(addon).off('click').on('click', function (e) { e.preventDefault(); e.stopPropagation(); proxy.focus(); openPicker(orig); });
		}
	}
	function scanInputs(root) {
		if (!root || !root.querySelectorAll) { return; }
		if (root.matches && root.matches('input.dateField')) { enhanceDateInput(root); }
		Array.prototype.forEach.call(root.querySelectorAll('input.dateField'), enhanceDateInput);
	}

	/* --------- the Jalali picker popup */
	function el(tag, cls, text) { var e = document.createElement(tag); if (cls) { e.className = cls; } if (text != null) { e.textContent = text; } return e; }
	function todayJ() { var d = new Date(); return PC.toJalali(d.getFullYear(), d.getMonth() + 1, d.getDate()); }

	function openPicker(orig) {
		if (orig._pcProxy.readOnly || orig._pcProxy.disabled) { return; }
		if (picker && picker.orig === orig) { return; }
		closePicker();
		var range = orig.getAttribute('data-calendar-type') === 'range';
		var cur = [];
		(orig.value || '').split(',').forEach(function (x) {
			var g = fmt.parse(x.trim()); if (g) { cur.push(PC.toJalali(g.gy, g.gm, g.gd)); }
		});
		var base = cur[0] || todayJ();
		picker = { orig: orig, range: range, sel: cur, view: { jy: base.jy, jm: base.jm }, box: el('div', 'pc-picker') };
		picker.box.setAttribute('dir', 'rtl');
		document.body.appendChild(picker.box);
		renderPicker();
		positionPicker();
	}
	function closePicker() {
		if (picker) { picker.box.parentNode && picker.box.parentNode.removeChild(picker.box); picker = null; }
	}
	function positionPicker() {
		if (!picker) { return; }
		var r = picker.orig._pcProxy.getBoundingClientRect(), b = picker.box;
		var top = r.bottom + window.pageYOffset + 2, left = r.right + window.pageXOffset - b.offsetWidth;
		if (left < 4) { left = r.left + window.pageXOffset; }
		if (r.bottom + b.offsetHeight > window.innerHeight && r.top > b.offsetHeight) { top = r.top + window.pageYOffset - b.offsetHeight - 2; }
		b.style.top = top + 'px';
		b.style.left = left + 'px';
	}
	function sameJ(a, b) { return a && b && a.jy === b.jy && a.jm === b.jm && a.jd === b.jd; }
	function cmpJ(a, b) { return (a.jy - b.jy) * 10000 + (a.jm - b.jm) * 100 + (a.jd - b.jd); }
	function renderPicker() {
		var p = picker, box = p.box, v = p.view;
		box.innerHTML = '';
		var head = el('div', 'pc-head');
		var prev = el('button', 'pc-nav', '‹'), next = el('button', 'pc-nav', '›');
		prev.type = next.type = 'button';
		prev.title = 'ماه قبل'; next.title = 'ماه بعد';
		var title = el('span', 'pc-title');
		var msel = el('select', 'pc-msel'), ysel = el('input', 'pc-ysel');
		PC.MONTHS.forEach(function (m, i) { var o = el('option', null, m); o.value = i + 1; if (i + 1 === v.jm) { o.selected = true; } msel.appendChild(o); });
		ysel.type = 'number'; ysel.value = v.jy; ysel.min = 1300; ysel.max = 1500;
		title.appendChild(msel); title.appendChild(ysel);
		head.appendChild(prev); head.appendChild(title); head.appendChild(next);
		box.appendChild(head);
		prev.onclick = function () { v.jm -= 1; if (v.jm < 1) { v.jm = 12; v.jy -= 1; } renderPicker(); };
		next.onclick = function () { v.jm += 1; if (v.jm > 12) { v.jm = 1; v.jy += 1; } renderPicker(); };
		msel.onchange = function () { v.jm = +msel.value; renderPicker(); };
		ysel.onchange = function () { var y = +ysel.value; if (y >= 1300 && y <= 1500) { v.jy = y; renderPicker(); } };

		var grid = el('div', 'pc-grid');
		[6, 0, 1, 2, 3, 4, 5].forEach(function (w) { grid.appendChild(el('span', 'pc-wd', PC.WEEKDAYS_SHORT[w])); });
		var g1 = PC.toGregorian(v.jy, v.jm, 1);
		var firstDow = new Date(g1.gy, g1.gm - 1, g1.gd).getDay();      // 0 = Sunday
		var offset = (firstDow + 1) % 7;                               // Saturday-first
		for (var i = 0; i < offset; i++) { grid.appendChild(el('span', 'pc-empty')); }
		var len = PC.monthLength(v.jy, v.jm), t = todayJ();
		for (var d = 1; d <= len; d++) {
			(function (d) {
				var j = { jy: v.jy, jm: v.jm, jd: d }, b = el('button', 'pc-day', PC.faDigits(d));
				b.type = 'button';
				var dow = (offset + d - 1) % 7;
				if (dow === 6) { b.classList.add('pc-fri'); }
				if (sameJ(j, t)) { b.classList.add('pc-today'); }
				if (p.sel.some(function (s) { return sameJ(s, j); })) { b.classList.add('pc-sel'); }
				if (p.range && p.sel.length === 2 && cmpJ(j, p.sel[0]) > 0 && cmpJ(j, p.sel[1]) < 0) { b.classList.add('pc-inrange'); }
				b.onclick = function () { pick(j); };
				grid.appendChild(b);
			})(d);
		}
		box.appendChild(grid);

		var foot = el('div', 'pc-foot');
		var bt = el('button', 'pc-btn', 'امروز'), bc = el('button', 'pc-btn', 'پاک کردن'), bx = el('button', 'pc-btn', 'بستن');
		bt.type = bc.type = bx.type = 'button';
		bt.onclick = function () { if (p.range) { p.view = { jy: t.jy, jm: t.jm }; renderPicker(); } else { pick(t); } };
		bc.onclick = function () { p.orig._pcProxy.value = ''; setOrig(p.orig, ''); closePicker(); };
		bx.onclick = closePicker;
		foot.appendChild(bt); foot.appendChild(bc); foot.appendChild(bx);
		if (p.range) { foot.appendChild(el('span', 'pc-hint', p.sel.length === 1 ? 'تاریخ پایان را انتخاب کنید' : 'تاریخ شروع را انتخاب کنید')); }
		box.appendChild(foot);
	}
	function pick(j) {
		var p = picker, orig = p.orig;
		if (p.range) {
			if (p.sel.length !== 1) { p.sel = [j]; renderPicker(); return; }
			p.sel.push(j);
			p.sel.sort(cmpJ);
			var vals = p.sel.map(function (s) { return fmt.build(PC.toGregorian(s.jy, s.jm, s.jd)); });
			setOrig(orig, vals.join(','));
		} else {
			setOrig(orig, fmt.build(PC.toGregorian(j.jy, j.jm, j.jd)));
		}
		syncProxy(orig);
		closePicker();
		if (p.range) { $(orig).trigger($.Event('keypress', { which: 13, keyCode: 13 })); }
	}

	/* ------------------------------------------------------------------ FullCalendar (Calendar module) */
	function jTitle(start, endExcl) {
		var a = PC.toJalali(start.year(), start.month() + 1, start.date());
		var e = endExcl.clone().subtract(1, 'days');
		var b = PC.toJalali(e.year(), e.month() + 1, e.date());
		if (sameJ(a, b)) { return PC.WEEKDAYS[start.day()] + ' ' + PC.faDigits(a.jd) + ' ' + PC.MONTHS[a.jm - 1] + ' ' + PC.faDigits(a.jy); }
		if (a.jy === b.jy && a.jm === b.jm) { return PC.MONTHS[a.jm - 1] + ' ' + PC.faDigits(a.jy); }
		if (a.jy === b.jy) { return PC.MONTHS[a.jm - 1] + ' – ' + PC.MONTHS[b.jm - 1] + ' ' + PC.faDigits(a.jy); }
		return PC.MONTHS[a.jm - 1] + ' ' + PC.faDigits(a.jy) + ' – ' + PC.MONTHS[b.jm - 1] + ' ' + PC.faDigits(b.jy);
	}
	function jalaliizeCalendar(view, element) {
		var root = $(element).closest('.fc');
		if (!root.length) { root = $(element).parent(); }
		var useInterval = view.name === 'month';
		var s = useInterval ? view.intervalStart : view.start, e = useInterval ? view.intervalEnd : view.end;
		if (s && e) { root.find('.fc-toolbar h2, .fc-center h2').first().text(jTitle(s, e)); }
		root.find('.fc-day-top[data-date], td.fc-day-number[data-date]').each(function () {
			var iso = this.getAttribute('data-date').split('-'), j = PC.toJalali(+iso[0], +iso[1], +iso[2]);
			var target = $(this).hasClass('fc-day-number') ? $(this) : $(this).find('.fc-day-number');
			var label = PC.faDigits(j.jd) + (j.jd === 1 ? ' ' + PC.MONTHS[j.jm - 1] : '');
			target.html('<span class="pc-jd">' + label + '</span><span class="pc-gd">' + (+iso[2]) + '</span>');
		});
		root.find('th.fc-day-header').each(function () {
			var dd = this.getAttribute('data-date'), cls = this.className, idx = ['sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'].findIndex(function (k) { return cls.indexOf('fc-' + k) !== -1; });
			var txt = idx >= 0 ? PC.WEEKDAYS[idx] : $(this).text();
			if (dd) {
				var iso = dd.split('-'), j = PC.toJalali(+iso[0], +iso[1], +iso[2]);
				txt += ' ' + PC.faDigits(j.jd) + ' ' + PC.MONTHS[j.jm - 1];
			}
			var a = $(this).find('a, span').first();
			(a.length ? a : $(this)).text(txt);
		});
		// list (agenda) view headings
		root.find('.fc-list-heading[data-date]').each(function () {
			var iso = this.getAttribute('data-date').split('-'), j = PC.toJalali(+iso[0], +iso[1], +iso[2]);
			var dt = new Date(+iso[0], +iso[1] - 1, +iso[2]);
			$(this).find('.fc-list-heading-main').text(PC.WEEKDAYS[dt.getDay()] + ' ' + PC.faDigits(j.jd) + ' ' + PC.MONTHS[j.jm - 1] + ' ' + PC.faDigits(j.jy));
		});
	}
	/* Vtiger's class system copies parent methods into subclasses when they are defined, so patching
	   Calendar_Calendar_Js.prototype does not reach Calendar_SharedCalendar_Js etc. Instead the
	   FullCalendar plugin itself is wrapped: every calendar initialised with an options object gets
	   RTL, Saturday as first day, Persian weekday names and the Jalali relabelling. */
	function jalaliOptions(cfg) {
		cfg = $.extend({}, cfg);
		cfg.isRTL = true;
		cfg.firstDay = 6;
		cfg.dayNames = PC.WEEKDAYS.slice();
		cfg.dayNamesShort = PC.WEEKDAYS.slice();
		cfg.eventLimitText = function (n) { return '+' + PC.faDigits(n) + ' مورد دیگر'; };
		var vr = cfg.viewRender, ar = cfg.eventAfterAllRender;
		cfg.viewRender = function (view, element) { if (vr) { vr.apply(this, arguments); } jalaliizeCalendar(view, element); };
		cfg.eventAfterAllRender = function (view) { if (ar) { ar.apply(this, arguments); } jalaliizeCalendar(view, view.el); };
		return cfg;
	}
	function wrapFullCalendar(orig) {
		if (!orig || orig._pcPatched) { return orig; }
		var wrapped = function (opt) {
			var args = Array.prototype.slice.call(arguments);
			if (opt && typeof opt === 'object' && active()) {
				if (!fmt) { setupFormat(document.body.getAttribute('data-user-dateformat')); }
				args[0] = jalaliOptions(opt);
			}
			return orig.apply(this, args);
		};
		$.extend(wrapped, orig);
		wrapped._pcPatched = true;
		return wrapped;
	}
	/* fullcalendar.js is loaded after this file and Vtiger initialises the calendar before
	   document-ready, so trap the moment the plugin is defined on $.fn. */
	function patchCalendar() {
		if (!$ || !$.fn) { return; }
		var desc = Object.getOwnPropertyDescriptor($.fn, 'fullCalendar');
		if (desc && desc.get) { return; }                       // trap already installed
		var current = wrapFullCalendar($.fn.fullCalendar);
		try {
			Object.defineProperty($.fn, 'fullCalendar', {
				configurable: true, enumerable: true,
				get: function () { return current; },
				set: function (v) { current = wrapFullCalendar(v); }
			});
		} catch (e) { $.fn.fullCalendar = current; }
	}

	/* ------------------------------------------------------------------ bootstrap */
	function active() { return document.body && /^fa/i.test(document.body.getAttribute('data-language') || ''); }

	function patchJquery() {
		if (!$ || $.fn._pcPatched) { return; }
		$.fn._pcPatched = true;
		// never attach Gregorian pickers to Vtiger date fields – they get the Jalali twin instead
		['datepicker', 'dateRangePicker'].forEach(function (name) {
			var orig = $.fn[name];
			if (!orig) { return; }
			$.fn[name] = function (opt) {
				var others = this.filter(function () { return !(this.classList && this.classList.contains('dateField')); });
				var dates = this.not(others);
				dates.each(function () { enhanceDateInput(this); if (typeof opt === 'string') { syncProxy(this); } });
				if (others.length) { orig.apply(others, arguments); }
				return this;
			};
			$.extend($.fn[name], orig);
		});
		// keep the Jalali twin in sync when Vtiger sets a date programmatically: $(el).val('2026-01-01')
		var hook = $.valHooks.text, prevSet = hook && hook.set;
		$.valHooks.text = $.extend({}, hook, {
			set: function (elem, value) {
				var r = prevSet ? prevSet(elem, value) : undefined;
				if (r === undefined) { elem.value = value; }
				if (elem._pcProxy && !elem._pcSetting) { syncProxy(elem); }
				return true;
			}
		});
	}

	function start() {
		if (!active()) { return; }
		document.documentElement.setAttribute('lang', 'fa');
		setupFormat(document.body.getAttribute('data-user-dateformat'));
		patchJquery();
		convertTree(document.body);
		scanInputs(document.body);
		var pending = [], scheduled = false;
		new MutationObserver(function (muts) {
			muts.forEach(function (m) {
				if (m.type === 'characterData') { pending.push(m.target); }
				Array.prototype.forEach.call(m.addedNodes, function (n) { pending.push(n); });
			});
			if (!scheduled) {
				scheduled = true;
				(window.requestAnimationFrame || setTimeout)(function () {
					scheduled = false;
					var list = pending; pending = [];
					list.forEach(function (n) {
						if (picker && picker.box.contains(n)) { return; }
						convertTree(n);
						if (n.nodeType === 1) { scanInputs(n); }
					});
				});
			}
		}).observe(document.body, { childList: true, subtree: true, characterData: true });
		document.addEventListener('mousedown', function (e) {
			if (picker && !picker.box.contains(e.target) && e.target !== picker.orig._pcProxy && !$(e.target).closest('.input-group-addon').length) { closePicker(); }
		}, true);
		window.addEventListener('resize', positionPicker);
		window.addEventListener('scroll', positionPicker, true);
	}

	window.PersianCalendar = PC;
	PC._convertText = function (t) { if (!fmt) { setupFormat('yyyy-mm-dd'); } return convertText(t); };
	PC._setupFormat = setupFormat;
	if (typeof module !== 'undefined' && module.exports) { module.exports = PC; return; }   // node tests
	patchCalendar();
	if ($) { $(start); } else { document.addEventListener('DOMContentLoaded', start); }
})(typeof window !== 'undefined' ? window : globalThis, typeof document !== 'undefined' ? document : null);
