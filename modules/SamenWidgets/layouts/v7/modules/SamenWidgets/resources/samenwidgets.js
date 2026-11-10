/*+**********************************************************************************
 * SamenWidgets – header bell (overdue / due-today tasks and events), Vtiger reminder pop-ups collected into the
 * bell instead of floating over the menu, and the dashboard widgets (Jalali mini calendar, Chart.js charts).
 ************************************************************************************/
(function (window, document) {
	'use strict';
	var BASE = 'layouts/v7/modules/SamenWidgets/resources/';
	var VERSION = '1.0.4';
	var POLL = 120000;
	var COLORS = ['#13606F', '#D99058', '#7D4F2E', '#4F7CAC', '#0F766E', '#E11D48', '#A3C2E0', '#F59E0B', '#64748B', '#22C55E', '#8B5CF6', '#0EA5E9'];

	function q(s, r) { return (r || document).querySelector(s); }
	function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') { e.textContent = attrs[k]; } else { e.setAttribute(k, attrs[k]); } });
		(kids || []).forEach(function (c) { if (c != null) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return e;
	}
	function fa(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
	function PC() { return window.PersianCalendar && window.PersianCalendar.toJalali ? window.PersianCalendar : null; }
	function jdate(g) {          // "2026-11-10" -> "۱۴۰۵/۰۸/۱۹"
		var p = PC(), m = /^(\d{4})-(\d{2})-(\d{2})/.exec(g || '');
		if (!p || !m) { return g || ''; }
		var j = p.toJalali(+m[1], +m[2], +m[3]);
		return fa(j.jy + '/' + ('0' + j.jm).slice(-2) + '/' + ('0' + j.jd).slice(-2));
	}

	/* ------------------------------------------------------------------ bell */
	var bell, badge, panel, data = { overdue: [], today: [], count: 0 }, reminders = [];
	function buildBell() {
		if (q('#samenBell') || !q('.app-fixed-navbar, #page')) { return; }
		bell = el('button', { type: 'button', id: 'samenBell', class: 'sw-bell', title: 'کارها و رویدادهای سررسیدشده', 'aria-haspopup': 'true', 'aria-expanded': 'false' }, [
			el('i', { class: 'fa fa-bell', 'aria-hidden': 'true' }), el('span', { class: 'sw-badge', hidden: '' })
		]);
		badge = q('.sw-badge', bell);
		var host = q('#navbar .global-nav ul, .global-nav .nav.navbar-nav, #navbar ul.nav');
		if (host) { host.insertBefore(el('li', { class: 'sw-bell-li' }, [bell]), host.firstChild); }
		else { bell.classList.add('floating'); document.body.appendChild(bell); }
		var fab = el('button', { type: 'button', class: 'sw-bell-fab', 'aria-label': 'کارها و رویدادهای سررسیدشده' }, [el('i', { class: 'fa fa-bell' }), el('span', { class: 'sw-badge', hidden: '' })]);
		document.body.appendChild(fab);
		panel = el('div', { class: 'sw-panel', role: 'dialog', 'aria-label': 'کارها و رویدادهای سررسیدشده', hidden: '', dir: 'rtl' });
		document.body.appendChild(panel);
		var toggle = function (e) { e.stopPropagation(); if (panel.hidden) { openPanel(e.currentTarget); } else { closePanel(); } };
		bell.addEventListener('click', toggle); fab.addEventListener('click', toggle);
		document.addEventListener('click', function (e) { if (!panel.hidden && !panel.contains(e.target)) { closePanel(); } });
		document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closePanel(); } });
		refresh();
		setInterval(refresh, POLL);
	}
	function setBadge() {
		var n = data.count + reminders.length;
		qa('.sw-badge').forEach(function (b) { b.hidden = !n; b.textContent = n > 99 ? '۹۹+' : fa(n); });
		qa('.sw-bell, .sw-bell-fab').forEach(function (b) { b.classList.toggle('has', !!n); });
	}
	function refresh() {
		fetch('index.php?module=SamenWidgets&action=Due', { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.then(function (r) { return r.json(); })
			.then(function (j) { if (j && j.result) { data = j.result; setBadge(); if (panel && !panel.hidden) { renderPanel(); } } })
			.catch(function () { /* offline: keep last state */ });
	}
	function item(a, cls) {
		var when = cls === 'today' ? 'امروز' : jdate(a.date);
		if (a.time) { when += ' · ' + fa(a.time); }
		return el('li', { class: cls }, [
			el('a', { href: 'index.php?module=Calendar&view=Detail&record=' + a.id }, [
				el('i', { class: 'fa ' + (a.type === 'task' ? 'fa-check-square-o' : 'fa-calendar'), 'aria-hidden': 'true' }), ' ' + a.subject
			]),
			el('small', { text: when + (a.contact ? ' · ' + a.contact : '') })
		]);
	}
	function renderPanel() {
		panel.innerHTML = '';
		panel.appendChild(el('header', {}, [el('b', { text: 'کارهای بدون زمان باقی‌مانده' }),
			el('span', {}, [el('span', { class: 'sw-pill sw-red', text: 'گذشته: ' + fa(data.overdue_count || 0) }), ' ',
				el('span', { class: 'sw-pill sw-amber', text: 'امروز: ' + fa(data.today_count || 0) })])]));
		var ul = el('ul', { class: 'sw-due-list' });
		reminders.forEach(function (r) {
			ul.appendChild(el('li', { class: 'reminder' }, [el('a', { href: 'index.php?module=Calendar&view=Detail&record=' + r.id }, [el('i', { class: 'fa fa-bell-o' }), ' ' + (r.subject || '')]),
				el('small', { text: 'یادآوری · ' + (r.date_start || '') })]));
		});
		(data.today || []).forEach(function (a) { ul.appendChild(item(a, 'today')); });
		(data.overdue || []).forEach(function (a) { ul.appendChild(item(a, 'overdue')); });
		if (!ul.children.length) { panel.appendChild(el('p', { class: 'sw-empty', text: 'کاری با زمان تمام‌شده ندارید. عالی است!' })); }
		else { panel.appendChild(ul); }
		panel.appendChild(el('footer', {}, [
			el('a', { href: 'index.php?module=Calendar&view=List', text: 'همه فعالیت‌ها' }),
			el('a', { href: 'index.php?module=Calendar&view=Calendar', text: 'تقویم' })
		]));
	}
	function openPanel(anchor) {
		renderPanel();
		panel.hidden = false;
		var r = anchor.getBoundingClientRect(), w = Math.min(380, window.innerWidth - 16);
		panel.style.width = w + 'px';
		if (anchor.classList.contains('sw-bell-fab')) { panel.style.left = '8px'; panel.style.top = 'auto'; panel.style.bottom = '76px'; }
		else { panel.style.bottom = 'auto'; panel.style.top = (r.bottom + 8) + 'px'; panel.style.left = Math.max(8, Math.min(window.innerWidth - w - 8, r.left)) + 'px'; }
		qa('.sw-bell, .sw-bell-fab').forEach(function (b) { b.setAttribute('aria-expanded', 'true'); });
		reminders = []; setBadge();
	}
	function closePanel() {
		if (!panel || panel.hidden) { return; }
		panel.hidden = true;
		qa('.sw-bell, .sw-bell-fab').forEach(function (b) { b.setAttribute('aria-expanded', 'false'); });
	}

	/* Vtiger's reminder pop-ups float over the (right-hand) menu: collect them in the bell instead */
	function hookReminders() {
		var V = window.Vtiger_Index_Js;
		if (!V || V._samenHooked) { return; }
		V._samenHooked = true;
		V.showReminderPopup = function (record) {
			if (!record || reminders.some(function (r) { return r.id === record.id; })) { return; }
			reminders.push({ id: record.id, subject: (record.subject || '').replace(/<[^>]*>/g, ''), date_start: record.date_start });
			setBadge();
			qa('.sw-bell, .sw-bell-fab').forEach(function (b) { b.classList.remove('ring'); void b.offsetWidth; b.classList.add('ring'); });
		};
	}

	/* ------------------------------------------------------------------ mini calendar */
	function renderCalendar(box) {
		var p = PC();
		if (!p) { box.textContent = 'تقویم شمسی در دسترس نیست.'; return; }
		var days = {}; try { days = JSON.parse(box.getAttribute('data-days') || '{}'); } catch (e) { days = {}; }
		var t = (box.getAttribute('data-today') || '').split('-').map(Number);
		var tj = p.toJalali(t[0], t[1], t[2]);
		var view = { jy: tj.jy, jm: tj.jm };
		var draw = function () {
			box.innerHTML = '';
			var prev = el('button', { type: 'button', class: 'sw-cal-nav', 'aria-label': 'ماه قبل' }, [el('i', { class: 'fa fa-chevron-right' })]);
			var next = el('button', { type: 'button', class: 'sw-cal-nav', 'aria-label': 'ماه بعد' }, [el('i', { class: 'fa fa-chevron-left' })]);
			prev.onclick = function () { view.jm--; if (view.jm < 1) { view.jm = 12; view.jy--; } draw(); };
			next.onclick = function () { view.jm++; if (view.jm > 12) { view.jm = 1; view.jy++; } draw(); };
			box.appendChild(el('div', { class: 'sw-cal-head' }, [prev, el('b', { text: p.MONTHS[view.jm - 1] + ' ' + fa(view.jy) }), next]));
			var grid = el('div', { class: 'sw-cal-grid' });
			['ش', 'ی', 'د', 'س', 'چ', 'پ', 'ج'].forEach(function (d) { grid.appendChild(el('span', { class: 'sw-wd', text: d })); });
			var g1 = p.toGregorian(view.jy, view.jm, 1);
			var first = new Date(g1.gy, g1.gm - 1, g1.gd).getDay();          // 0 = Sunday
			var lead = (first + 1) % 7;                                           // Saturday first
			for (var i = 0; i < lead; i++) { grid.appendChild(el('span', { class: 'sw-day empty' })); }
			var len = view.jm <= 6 ? 31 : (view.jm <= 11 ? 30 : (p.toGregorian(view.jy, 12, 30).gd ? 30 : 29));
			for (var d = 1; d <= len; d++) {
				var g = p.toGregorian(view.jy, view.jm, d);
				if (view.jm === 12 && d === 30) { var back = p.toJalali(g.gy, g.gm, g.gd); if (back.jm !== 12) { break; } }
				var key = g.gy + '-' + ('0' + g.gm).slice(-2) + '-' + ('0' + g.gd).slice(-2);
				var n = days[key] || 0;
				var isToday = view.jy === tj.jy && view.jm === tj.jm && d === tj.jd;
				var cell = el('a', { class: 'sw-day' + (isToday ? ' today' : '') + (n ? ' busy' : '') + ((lead + d) % 7 === 0 ? ' fri' : ''),
					href: 'index.php?module=Calendar&view=Calendar', title: n ? fa(n) + ' کار باز' : '' }, [el('span', { text: fa(d) })]);
				if (n) { cell.appendChild(el('i', { class: 'sw-dot', text: n > 9 ? '+' : fa(n) })); }
				grid.appendChild(cell);
			}
			box.appendChild(grid);
			box.appendChild(el('div', { class: 'sw-cal-legend' }, [el('span', { class: 'sw-dot-demo' }), ' روزهایی که کار باز دارند · ', el('span', { class: 'sw-today-demo' }), ' امروز']));
		};
		draw();
	}

	/* ------------------------------------------------------------------ charts (Chart.js, loaded once) */
	var chartLoading = null;
	function loadChart() {
		if (window.Chart) { return Promise.resolve(window.Chart); }
		if (chartLoading) { return chartLoading; }
		chartLoading = new Promise(function (resolve, reject) {
			var amd = window.define;              // Chart.js would register as an AMD module if a loader is present
			window.define = undefined;
			var s = el('script', { src: BASE + 'lib/chart.umd.js?v=' + VERSION });
			s.onload = function () { window.define = amd; resolve(window.Chart); };
			s.onerror = function () { window.define = amd; reject(); };
			document.head.appendChild(s);
		});
		return chartLoading;
	}
	function renderChart(box) {
		var spec; try { spec = JSON.parse(box.getAttribute('data-chart')); } catch (e) { return; }
		var canvas = q('canvas', box);
		loadChart().then(function (Chart) {
			Chart.defaults.font.family = 'Shabnam, Tahoma, sans-serif';
			Chart.defaults.color = '#475569';
			var labels, datasets, type = spec.type, opts = { responsive: true, maintainAspectRatio: false, locale: 'fa-IR',
				plugins: { legend: { rtl: true, position: 'bottom', labels: { boxWidth: 12, usePointStyle: true } },
					tooltip: { rtl: true, textDirection: 'rtl', callbacks: { label: function (c) {
						var v = c.parsed && typeof c.parsed === 'object' ? (c.parsed.x != null && type === 'hbar' ? c.parsed.x : c.parsed.y) : c.parsed;
						if (v == null) { v = c.raw; }
						var total = c.dataset.data.reduce(function (a, b) { return a + b; }, 0);
						var pct = (type === 'pie' || type === 'doughnut' || type === 'polarArea') && total ? ' (' + fa(Math.round(100 * v / total)) + '٪)' : '';
						return ' ' + (c.dataset.label || c.label) + ': ' + fa(Number(v).toLocaleString('en-US')) + pct;
					} } } } };
			if (spec.series) {
				labels = spec.labels;
				datasets = Object.keys(spec.series).map(function (name, i) {
					return { label: name, data: spec.series[name], borderColor: COLORS[i], backgroundColor: COLORS[i] + (type === 'line' ? '22' : 'CC'),
						fill: type === 'line', tension: 0.35, pointRadius: 3, borderWidth: 2, borderRadius: 6 };
				});
				if (type === 'barline') {
					type = 'bar';
					datasets.push({ type: 'line', label: 'روند', data: datasets[0].data, borderColor: COLORS[1], backgroundColor: COLORS[1], tension: 0.35, pointRadius: 2, borderWidth: 2 });
				}
			} else {
				labels = Object.keys(spec.data);
				var vals = labels.map(function (k) { return spec.data[k]; });
				var round = type === 'pie' || type === 'doughnut' || type === 'polarArea';
				datasets = [{ label: q('.dashboardWidgetHeader .dashboardTitle, .dashboardWidgetHeader h5, .dashboardWidgetHeader', box.closest('.dashboardWidget') || document) ? 'تعداد' : 'تعداد',
					data: vals, backgroundColor: round ? labels.map(function (l, i) { return COLORS[i % COLORS.length] + (type === 'polarArea' ? 'BB' : ''); }) : COLORS[0],
					hoverBackgroundColor: round ? undefined : COLORS[1], borderColor: '#fff', borderWidth: round ? 2 : 0, borderRadius: round ? 0 : 6, maxBarThickness: 34 }];
				if (!round) { opts.plugins.legend.display = false; }
				if (type === 'hbar') { type = 'bar'; opts.indexAxis = 'y'; }
				if (type === 'doughnut') { opts.cutout = '62%'; }
			}
			if (type === 'bar' || type === 'line') {
				var tick = { callback: function (v) { var l = this.getLabelForValue ? this.getLabelForValue(v) : v; l = fa(String(l)); return l.length > 22 ? l.slice(0, 21) + '…' : l; } };
				var num = { callback: function (v) { return fa(v); }, precision: 0 };
				opts.scales = opts.indexAxis === 'y' ? { x: { ticks: num, beginAtZero: true, grid: { color: '#EEF2F6' } }, y: { ticks: tick, position: 'right', grid: { display: false } } }
					: { x: { ticks: tick, reverse: true, grid: { display: false } }, y: { ticks: num, beginAtZero: true, position: 'right', grid: { color: '#EEF2F6' } } };
			}
			if (box._chart) { box._chart.destroy(); }
			box._chart = new Chart(canvas, { type: type, data: { labels: labels, datasets: datasets }, options: opts });
		}).catch(function () { box.textContent = 'نمودار بارگذاری نشد.'; });
	}

	function scan(root) {
		qa('.sw-cal:not([data-done])', root).forEach(function (b) { b.setAttribute('data-done', '1'); renderCalendar(b); });
		qa('.sw-chart:not([data-done])', root).forEach(function (b) { b.setAttribute('data-done', '1'); renderChart(b); });
	}

	function start() {
		hookReminders();
		buildBell();
		scan(document);
		// widgets arrive by AJAX (dashboard load, refresh, add widget)
		new MutationObserver(function (m) {
			m.forEach(function (r) { Array.prototype.forEach.call(r.addedNodes, function (n) { if (n.nodeType === 1) { scan(n.parentNode || n); } }); });
		}).observe(document.body, { childList: true, subtree: true });
	}
	hookReminders();
	window.SamenWidgets = { refresh: refresh, scan: scan };
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})(window, document);
