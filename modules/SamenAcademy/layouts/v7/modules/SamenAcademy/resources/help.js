/*+**********************************************************************************
 * SamenAcademy – "راهنما" on every page (HEADERSCRIPT link).
 *  - a help button in the top bar (and the keyboard shortcut "?" / F1)
 *  - a side panel: what this page is for, step-by-step tasks, sales & marketing tips,
 *    every field visible on the page with what it means and how it is used, links to lessons
 *  - "show field guide" marks every field label on the page; hovering / tapping it shows its explanation
 * Content: resources/help/pages.json (per module:view) and resources/help/fields.json (per module, by field label).
 ************************************************************************************/
(function (window, document) {
	'use strict';
	var BASE = 'layouts/v7/modules/SamenAcademy/resources/';
	var VERSION = '1.1.4';
	var ACADEMY = 'index.php?module=SamenAcademy&view=Index';
	var data = null, loading = null;

	function q(sel, root) { return (root || document).querySelector(sel); }
	function qa(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
	function el(tag, attrs, children) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) {
			if (k === 'text') { e.textContent = attrs[k]; } else if (k === 'html') { e.innerHTML = attrs[k]; } else { e.setAttribute(k, attrs[k]); }
		});
		(children || []).forEach(function (c) { if (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return e;
	}
	function norm(s) { return (s || '').replace(/[‌‏‎*:]/g, ' ').replace(/\s+/g, ' ').trim(); }

	function context() {
		var p = new URLSearchParams(window.location.search);
		var module = p.get('module') || 'Home', view = p.get('view') || (p.get('module') ? 'List' : 'DashBoard');
		if (p.get('parent') === 'Settings' && module !== 'Users') { return { module: 'Settings', view: module, raw: module, mode: p.get('mode') || '' }; }
		if (module === 'Users' && /Preference/.test(view)) { return { module: 'Users', view: 'Preferences', raw: module, mode: '' }; }
		if (module === 'Events') { module = 'Calendar'; }
		if (module === 'Calendar' && view === 'Edit' && p.get('mode') === 'Events') { view = 'EditEvent'; }
		return { module: module, view: view, raw: p.get('module') || 'Home', mode: p.get('mode') || '' };
	}

	function load() {
		if (data) { return Promise.resolve(data); }
		if (loading) { return loading; }
		var get = function (f) { return fetch(BASE + f + '?v=' + VERSION, { credentials: 'same-origin' }).then(function (r) { return r.json(); }); };
		loading = Promise.all([get('help/pages.json'), get('help/fields.json'), get('content/courses.json')]).then(function (res) {
			var lessons = {};
			res[2].courses.forEach(function (c) { c.lessons.forEach(function (l) { lessons[l[0]] = c.title + ' › ' + l[1]; }); });
			// field keys are matched after the same normalisation as the page labels (ZWNJ, "*", ":" and spacing)
			var fields = {};
			Object.keys(res[1]).forEach(function (m) {
				fields[m] = {};
				Object.keys(res[1][m]).forEach(function (k) { fields[m][norm(k)] = res[1][m][k]; });
			});
			data = { pages: res[0], fields: fields, lessons: lessons };
			return data;
		});
		return loading;
	}

	function pageHelp(ctx) {
		var P = data.pages;
		return P[ctx.module + ':' + ctx.view] || P[ctx.module + ':*'] || (ctx.module === 'Settings' ? P['Settings:*'] : null) || P['*'];
	}

	/* labels of the fields on the current page: edit/detail labels, list column headers, settings form labels */
	function pageLabels() {
		var seen = {}, out = [];
		qa('.fieldLabel, td.fieldLabel label, .listViewHeaders th, .listview-table th .listViewContentHeaderValues, .summaryViewEntries .fieldLabel, label.control-label, .tg-page label, .samen-ext dt').forEach(function (n) {
			if (!n.offsetWidth && !n.offsetHeight) { return; }
			var t = norm(n.textContent);
			if (!t || t.length > 60 || seen[t]) { return; }
			seen[t] = 1; out.push(t);
		});
		return out;
	}
	function fieldHelp(ctx, label) {
		var F = data.fields, mods = [ctx.module, ctx.raw, '*'];
		if (ctx.module === 'Calendar') { mods.splice(1, 0, 'Events'); }
		for (var i = 0; i < mods.length; i++) {
			var m = F[mods[i]];
			if (m && m[label]) { return m[label]; }
		}
		return null;
	}

	/* ------------------------------------------------------------------ panel */
	var panel, backdrop, lastFocus;
	function section(title, icon, body) {
		if (!body) { return null; }
		return el('section', { class: 'sh-sec' }, [el('h3', {}, [el('i', { class: 'fa ' + icon, 'aria-hidden': 'true' }), ' ' + title]), body]);
	}
	function list(items, ordered) {
		if (!items || !items.length) { return null; }
		return el(ordered ? 'ol' : 'ul', {}, items.map(function (t) { return el('li', { html: t }); }));
	}
	function render() {
		var ctx = context(), h = pageHelp(ctx) || {}, labels = pageLabels();
		var body = q('.sh-body', panel);
		body.innerHTML = '';
		q('.sh-title', panel).textContent = h.title || 'راهنمای این صفحه';
		if (h.summary) { body.appendChild(el('p', { class: 'sh-lead', html: h.summary })); }
		[section('کار با این صفحه، قدم به قدم', 'fa-list-ol', list(h.steps, true)),
		 section('نکته‌های فروش و بازاریابی', 'fa-line-chart', list(h.sales)),
		 section('اشتباه‌های رایج', 'fa-exclamation-triangle', list(h.mistakes)),
		 section('میان‌برها', 'fa-keyboard-o', list(h.shortcuts))].forEach(function (s) { if (s) { body.appendChild(s); } });

		var rows = labels.map(function (l) { var d = fieldHelp(ctx, l); return d ? { l: l, d: d } : null; }).filter(Boolean);
		if (rows.length) {
			var filter = el('input', { type: 'search', class: 'sh-filter', placeholder: 'جستجو در فیلدها…', 'aria-label': 'جستجو در فیلدها' });
			var dl = el('dl', { class: 'sh-fields' });
			rows.forEach(function (r) { dl.appendChild(el('dt', { text: r.l })); dl.appendChild(el('dd', { html: r.d })); });
			filter.addEventListener('input', function () {
				var v = norm(filter.value);
				qa('dt', dl).forEach(function (dt) {
					var hit = !v || dt.textContent.indexOf(v) > -1 || dt.nextElementSibling.textContent.indexOf(v) > -1;
					dt.hidden = dt.nextElementSibling.hidden = !hit;
				});
			});
			var toggle = el('button', { type: 'button', class: 'btn btn-default btn-sm sh-mark' }, [el('i', { class: 'fa fa-crosshairs' }), ' نشان‌دادن راهنما روی فیلدها']);
			toggle.addEventListener('click', function () { markFields(ctx); close(); });
			body.appendChild(section('فیلدهای این صفحه (' + rows.length + ')', 'fa-tags', el('div', {}, [toggle, filter, dl])));
		}
		var links = (h.lessons || []).map(function (id) {
			var t = data.lessons[id];
			return t ? el('li', {}, [el('a', { href: ACADEMY + '&lesson=' + id, text: t })]) : null;
		}).filter(Boolean);
		links.push(el('li', {}, [el('a', { href: ACADEMY, class: 'sh-all' }, [el('i', { class: 'fa fa-graduation-cap' }), ' همه دوره‌های آکادمی SamenCRM'])]));
		body.appendChild(section('آموزش بیشتر', 'fa-book', el('ul', { class: 'sh-links' }, links)));
	}

	function build() {
		backdrop = el('div', { class: 'sh-backdrop', hidden: '' });
		panel = el('aside', { class: 'sh-panel', role: 'dialog', 'aria-modal': 'true', 'aria-labelledby': 'shTitle', hidden: '', dir: 'rtl', tabindex: '-1' }, [
			el('header', { class: 'sh-head' }, [
				el('span', { class: 'sh-badge', 'aria-hidden': 'true' }, [el('i', { class: 'fa fa-life-ring' })]),
				el('div', {}, [el('small', { text: 'راهنمای SamenCRM' }), el('h2', { class: 'sh-title', id: 'shTitle' })]),
				el('button', { type: 'button', class: 'sh-close', 'aria-label': 'بستن راهنما' }, [el('i', { class: 'fa fa-times' })])
			]),
			el('div', { class: 'sh-body' })
		]);
		document.body.appendChild(backdrop);
		document.body.appendChild(panel);
		backdrop.addEventListener('click', close);
		q('.sh-close', panel).addEventListener('click', close);
		panel.addEventListener('keydown', function (e) { if (e.key === 'Escape') { close(); } });
	}
	function open() {
		if (!panel) { build(); }
		lastFocus = document.activeElement;
		q('.sh-body', panel).innerHTML = '<p class="sh-lead">در حال بارگذاری…</p>';
		panel.hidden = backdrop.hidden = false;
		document.documentElement.classList.add('sh-open');
		requestAnimationFrame(function () { panel.classList.add('in'); backdrop.classList.add('in'); });
		panel.focus();
		load().then(render, function () { q('.sh-body', panel).innerHTML = '<p class="sh-lead">راهنما بارگذاری نشد. اتصال را بررسی کنید.</p>'; });
	}
	function close() {
		if (!panel || panel.hidden) { return; }
		panel.classList.remove('in'); backdrop.classList.remove('in');
		document.documentElement.classList.remove('sh-open');
		setTimeout(function () { panel.hidden = backdrop.hidden = true; }, 200);
		if (lastFocus && lastFocus.focus) { lastFocus.focus(); }
	}

	/* ------------------------------------------------------------------ field guide on the page */
	var tip;
	function markFields(ctx) {
		document.documentElement.classList.add('sh-marking');
		if (!tip) {
			tip = el('div', { class: 'sh-tip', role: 'tooltip', hidden: '' });
			document.body.appendChild(tip);
			document.addEventListener('scroll', function () { tip.hidden = true; }, true);
		}
		qa('.fieldLabel, .listViewHeaders th, label.control-label').forEach(function (n) {
			var d = fieldHelp(ctx, norm(n.textContent));
			if (!d || n.classList.contains('sh-has')) { return; }
			n.classList.add('sh-has');
			n.setAttribute('tabindex', '0');
			var show = function () {
				tip.innerHTML = '';
				tip.appendChild(el('b', { text: norm(n.textContent) }));          // page text: never as HTML
				tip.appendChild(el('div', { html: d }));                           // help text: our own static JSON
				tip.hidden = false;
				var r = n.getBoundingClientRect(), w = Math.min(340, window.innerWidth - 24);
				tip.style.width = w + 'px';
				tip.style.top = (r.bottom + 8) + 'px';
				tip.style.left = Math.max(12, Math.min(window.innerWidth - w - 12, r.right - w)) + 'px';
			};
			n.addEventListener('mouseenter', show); n.addEventListener('focus', show); n.addEventListener('click', show);
			n.addEventListener('mouseleave', function () { tip.hidden = true; }); n.addEventListener('blur', function () { tip.hidden = true; });
		});
		bar();
	}
	function bar() {
		if (q('.sh-markbar')) { return; }
		var b = el('div', { class: 'sh-markbar', role: 'status' }, [
			el('i', { class: 'fa fa-crosshairs' }), ' راهنمای فیلدها روشن است: نشانگر را روی نام هر فیلد ببرید یا روی آن بزنید. ',
			el('button', { type: 'button', class: 'btn btn-default btn-xs', text: 'خاموش کردن' })
		]);
		q('button', b).addEventListener('click', function () {
			document.documentElement.classList.remove('sh-marking');
			b.parentNode.removeChild(b);
			if (tip) { tip.hidden = true; }
		});
		document.body.appendChild(b);
	}

	/* ------------------------------------------------------------------ button in the top bar */
	function addButton() {
		if (q('#samenHelpBtn')) { return; }
		var btn = el('button', { type: 'button', id: 'samenHelpBtn', class: 'samen-help-btn', title: 'راهنمای این صفحه (کلید ?)', 'aria-haspopup': 'dialog' }, [
			el('i', { class: 'fa fa-question-circle', 'aria-hidden': 'true' }), el('span', { text: 'راهنما' })
		]);
		btn.addEventListener('click', open);
		var host = q('#navbar .global-nav ul, .global-nav .nav.navbar-nav, #navbar ul.nav');
		if (host) {
			var li = el('li', { class: 'samen-help-li' }, [btn]);
			host.insertBefore(li, host.firstChild);
		} else {
			btn.classList.add('floating');
			document.body.appendChild(btn);
		}
		// phones: Vtiger folds the top-bar icons away – a floating help button is always reachable
		var fab = el('button', { type: 'button', class: 'samen-help-fab', 'aria-label': 'راهنمای این صفحه', 'aria-haspopup': 'dialog' }, [
			el('i', { class: 'fa fa-question', 'aria-hidden': 'true' })
		]);
		fab.addEventListener('click', open);
		document.body.appendChild(fab);
	}

	function start() {
		if (!document.body || q('.loginPageContainer') || !q('.app-fixed-navbar, #page')) { return; }
		addButton();
		document.addEventListener('keydown', function (e) {
			var t = e.target, typing = t && (t.isContentEditable || /^(INPUT|TEXTAREA|SELECT)$/.test(t.tagName));
			if ((e.key === '?' && !typing) || e.key === 'F1') { e.preventDefault(); if (panel && !panel.hidden) { close(); } else { open(); } }
		});
		if (/[?&]help=1/.test(window.location.search)) { open(); }
	}

	window.SamenHelp = { open: open, close: close, context: context };
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})(window, document);
