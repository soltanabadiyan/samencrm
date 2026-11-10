/*+**********************************************************************************
 * SamenAcademy – academy page: course catalogue, lesson reader, checklists, calculators, image zoom.
 * Lessons are static HTML fragments (content/lessons/<id>.html) written by us; checklist / progress state is
 * stored per CRM user in this browser (localStorage).
 ************************************************************************************/
(function (window, document) {
	'use strict';
	var BASE = 'layouts/v7/modules/SamenAcademy/resources/content/';
	var VERSION = '1.1.0';
	var root, user, catalog, flat = [], store;

	function q(s, r) { return (r || document).querySelector(s); }
	function qa(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
	function el(tag, attrs, kids) {
		var e = document.createElement(tag);
		Object.keys(attrs || {}).forEach(function (k) { if (k === 'text') { e.textContent = attrs[k]; } else { e.setAttribute(k, attrs[k]); } });
		(kids || []).forEach(function (c) { if (c) { e.appendChild(typeof c === 'string' ? document.createTextNode(c) : c); } });
		return e;
	}
	function fa(n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }

	/* ------------------------------------------------------------------ progress (per user, this browser) */
	function key(k) { return 'samenAcademy:' + user + ':' + k; }
	function load(k, def) { try { var v = window.localStorage.getItem(key(k)); return v ? JSON.parse(v) : def; } catch (e) { return def; } }
	function save(k, v) { try { window.localStorage.setItem(key(k), JSON.stringify(v)); } catch (e) { /* private mode: no progress */ } }
	function done() { return load('done', {}); }
	function setDone(id, on) { var d = done(); if (on) { d[id] = Date.now(); } else { delete d[id]; } save('done', d); }

	/* ------------------------------------------------------------------ navigation */
	function sidebar() {
		var nav = q('.ac-nav', root), d = done();
		nav.innerHTML = '';
		var search = el('input', { type: 'search', class: 'ac-search', placeholder: 'جستجو در درس‌ها…', 'aria-label': 'جستجو در درس‌ها' });
		nav.appendChild(search);
		nav.appendChild(el('a', { class: 'ac-home', href: '?module=SamenAcademy&view=Index', 'data-lesson': '' }, [el('i', { class: 'fa fa-th-large' }), ' همه دوره‌ها']));
		catalog.courses.forEach(function (c) {
			var count = c.lessons.filter(function (l) { return d[l[0]]; }).length;
			var det = el('details', { class: 'ac-course', open: '' }, [
				el('summary', {}, [el('i', { class: 'fa ' + c.icon, style: 'color:' + c.color }), ' ' + c.title, el('span', { class: 'ac-count', text: fa(count) + '/' + fa(c.lessons.length) })])
			]);
			var ol = el('ol');
			c.lessons.forEach(function (l) {
				ol.appendChild(el('li', { class: d[l[0]] ? 'done' : '' }, [el('a', { href: '?module=SamenAcademy&view=Index&lesson=' + l[0], 'data-lesson': l[0], text: l[1] })]));
			});
			det.appendChild(ol);
			nav.appendChild(det);
		});
		search.addEventListener('input', function () {
			var v = search.value.trim();
			qa('.ac-course', nav).forEach(function (det) {
				var any = false;
				qa('li', det).forEach(function (li) { var hit = !v || li.textContent.indexOf(v) > -1; li.hidden = !hit; any = any || hit; });
				det.hidden = !any; if (v) { det.open = true; }
			});
		});
	}
	function markActive(id) {
		qa('.ac-nav a', root).forEach(function (a) { a.classList.toggle('active', a.getAttribute('data-lesson') === id); if (a.getAttribute('data-lesson') === id) { a.setAttribute('aria-current', 'page'); } else { a.removeAttribute('aria-current'); } });
	}

	function home() {
		var main = q('.ac-main', root), d = done(), total = flat.length, n = flat.filter(function (l) { return d[l.id]; }).length;
		main.innerHTML = '';
		main.appendChild(el('section', { class: 'ac-hero' }, [
			el('div', {}, [
				el('h2', { text: 'آکادمی SamenCRM' }),
				el('p', { text: 'از اولین ورود تا فروشنده‌ای حرفه‌ای: کار با سامانه، فروش مشاوره‌ای، بازاریابی داده‌محور و مدیریت سامانه. هر درس تصویر شماره‌دار صفحه‌ها، نمودار روند کار، چک‌لیست و مثال عددی دارد.' }),
				el('div', { class: 'ac-progress', role: 'progressbar', 'aria-valuemin': '0', 'aria-valuemax': String(total), 'aria-valuenow': String(n), 'aria-label': 'پیشرفت شما' }, [
					el('span', { style: 'width:' + (total ? Math.round(100 * n / total) : 0) + '%' })
				]),
				el('small', { text: 'پیشرفت شما: ' + fa(n) + ' از ' + fa(total) + ' درس' })
			]),
			el('img', { src: 'layouts/v7/modules/SamenTheme/resources/img/emblem.png', alt: '', width: '96', height: '96' })
		]));
		main.appendChild(el('h3', { class: 'ac-sub', text: 'مسیر پیشنهادی' }));
		main.appendChild(el('ol', { class: 'flow ac-path' }, [
			el('li', { text: 'شروع کار با SamenCRM' }), el('li', { text: 'SamenCRM برای فروشنده' }),
			el('li', { text: 'دوره فروش حرفه‌ای' }), el('li', { text: 'دوره بازاریابی' })
		]));
		var grid = el('div', { class: 'ac-cards' });
		catalog.courses.forEach(function (c) {
			var cnt = c.lessons.filter(function (l) { return d[l[0]]; }).length;
			var first = c.lessons.filter(function (l) { return !d[l[0]]; })[0] || c.lessons[0];
			grid.appendChild(el('article', { class: 'ac-card', style: '--c:' + c.color }, [
				el('div', { class: 'ac-card-icon' }, [el('i', { class: 'fa ' + c.icon })]),
				el('h3', { text: c.title }),
				el('p', { class: 'ac-aud', text: c.audience }),
				el('p', { text: c.summary }),
				el('div', { class: 'ac-progress small' }, [el('span', { style: 'width:' + Math.round(100 * cnt / c.lessons.length) + '%' })]),
				el('div', { class: 'ac-card-foot' }, [
					el('small', { text: fa(c.lessons.length) + ' درس · ' + fa(cnt) + ' گذرانده' }),
					el('a', { class: 'btn btn-primary btn-sm', href: '?module=SamenAcademy&view=Index&lesson=' + first[0], 'data-lesson': first[0], text: cnt ? 'ادامه دوره' : 'شروع دوره' })
				])
			]));
		});
		main.appendChild(grid);
		markActive('');
		document.title = 'آکادمی SamenCRM | SamenCRM';
	}

	function lesson(id) {
		var i = -1;
		flat.forEach(function (l, k) { if (l.id === id) { i = k; } });
		if (i < 0) { return home(); }
		var L = flat[i], main = q('.ac-main', root);
		main.innerHTML = '<p class="ac-loading">در حال بارگذاری درس…</p>';
		markActive(id);
		fetch(BASE + 'lessons/' + id + '.html?v=' + VERSION, { credentials: 'same-origin' }).then(function (r) {
			if (!r.ok) { throw new Error(r.status); }
			return r.text();
		}).then(function (html) {
			main.innerHTML = '';
			main.appendChild(el('nav', { class: 'ac-crumb', 'aria-label': 'مسیر' }, [
				el('a', { href: '?module=SamenAcademy&view=Index', 'data-lesson': '', text: 'آکادمی' }), ' › ',
				el('span', { text: L.course.title }), ' › ', el('span', { text: 'درس ' + fa(L.n) })
			]));
			var art = el('article', { class: 'ac-lesson' });
			// our own static lesson file; still passed through Vtiger's bundled DOMPurify when it is loaded
			art.innerHTML = window.DOMPurify ? window.DOMPurify.sanitize(html, { ADD_ATTR: ['data-calc', 'data-v', 'data-id', 'open'] }) : html;
			if (!q('h2', art)) { art.insertBefore(el('h2', { text: L.title }), art.firstChild); }
			main.appendChild(art);
			enhance(art, id);
			var d = done();
			var btn = el('button', { type: 'button', class: 'btn ' + (d[id] ? 'btn-success' : 'btn-primary') + ' ac-done' }, [
				el('i', { class: 'fa ' + (d[id] ? 'fa-check-circle' : 'fa-circle-o') }), d[id] ? ' این درس را گذرانده‌اید' : ' درس را گذراندم'
			]);
			btn.addEventListener('click', function () { setDone(id, !done()[id]); sidebar(); lesson(id); });
			var prev = flat[i - 1], next = flat[i + 1];
			main.appendChild(el('footer', { class: 'ac-pager' }, [
				prev ? el('a', { class: 'btn btn-default', href: '?module=SamenAcademy&view=Index&lesson=' + prev.id, 'data-lesson': prev.id }, [el('i', { class: 'fa fa-arrow-right' }), ' ' + prev.title]) : el('span'),
				btn,
				next ? el('a', { class: 'btn btn-default', href: '?module=SamenAcademy&view=Index&lesson=' + next.id, 'data-lesson': next.id }, [next.title + ' ', el('i', { class: 'fa fa-arrow-left' })]) : el('span')
			]));
			document.title = L.title + ' | آکادمی SamenCRM';
			main.scrollIntoView({ block: 'start' });
			window.scrollTo(0, 0);
		}).catch(function () {
			main.innerHTML = '';
			main.appendChild(el('div', { class: 'alert alert-warning', text: 'این درس بارگذاری نشد. صفحه را دوباره باز کنید.' }));
		});
	}

	function go(id, push) {
		if (push) { window.history.pushState({ lesson: id }, '', '?module=SamenAcademy&view=Index' + (id ? '&lesson=' + id : '')); }
		if (id) { lesson(id); } else { home(); }
		root.classList.remove('nav-open');
	}

	/* ------------------------------------------------------------------ lesson components */
	var CALC = {
		conv: { label: 'نرخ تبدیل', inputs: [['a', 'تعداد تبدیل‌شده (مثلاً فروش)', 24], ['b', 'تعداد کل (مثلاً سرنخ)', 300]], out: function (v) { return pct(v.a / v.b); } },
		cpl: { label: 'هزینه هر سرنخ (CPL)', inputs: [['a', 'هزینه کمپین', 30000000], ['b', 'تعداد سرنخ', 150]], out: function (v) { return money(v.a / v.b); } },
		cac: { label: 'هزینه جذب هر مشتری (CAC)', inputs: [['a', 'کل هزینه بازاریابی و فروش دوره', 120000000], ['b', 'تعداد مشتری جذب‌شده', 40]], out: function (v) { return money(v.a / v.b); } },
		ltv: { label: 'ارزش طول عمر مشتری (LTV)', inputs: [['a', 'میانگین درآمد هر خرید', 45000000], ['b', 'تعداد خرید در طول ارتباط', 2.5], ['c', 'حاشیه سود (٪)', 40]], out: function (v) { return money(v.a * v.b * v.c / 100); } },
		ltvcac: { label: 'نسبت LTV به CAC', inputs: [['a', 'LTV', 45000000], ['b', 'CAC', 3000000]], out: function (v) { var r = v.a / v.b; return num(r) + ' : ۱ — ' + (r >= 3 ? 'سالم (۳ یا بیشتر)' : r >= 1 ? 'سودآور ولی ضعیف' : 'زیان‌ده'); } },
		roi: { label: 'بازگشت سرمایه (ROI)', inputs: [['a', 'درآمد حاصل', 400000000], ['b', 'هزینه', 120000000]], out: function (v) { return pct((v.a - v.b) / v.b); } },
		roas: { label: 'بازده هزینه تبلیغات (ROAS)', inputs: [['a', 'درآمد از تبلیغ', 90000000], ['b', 'هزینه تبلیغ', 20000000]], out: function (v) { return num(v.a / v.b) + ' برابر'; } },
		payback: { label: 'دوره بازگشت CAC (ماه)', inputs: [['a', 'CAC', 3000000], ['b', 'سود ماهانه هر مشتری', 1000000]], out: function (v) { return num(v.a / v.b) + ' ماه'; } },
		velocity: { label: 'سرعت خط لوله فروش (درآمد در روز)', inputs: [['a', 'تعداد فرصت‌های باز', 60], ['b', 'میانگین ارزش هر فرصت', 40000000], ['c', 'نرخ موفقیت (٪)', 25], ['d', 'طول چرخه فروش (روز)', 30]], out: function (v) { return money(v.a * v.b * (v.c / 100) / v.d) + ' در روز'; } },
		forecast: { label: 'پیش‌بینی وزنی', inputs: [['a', 'مجموع مبلغ فرصت‌های مرحله', 200000000], ['b', 'احتمال مرحله (٪)', 60]], out: function (v) { return money(v.a * v.b / 100); } },
		nps: { label: 'شاخص خالص ترویج‌کنندگان (NPS)', inputs: [['a', 'ترویج‌کنندگان (۹–۱۰)', 55], ['b', 'خنثی (۷–۸)', 25], ['c', 'منتقدان (۰–۶)', 20]], out: function (v) { var t = v.a + v.b + v.c; return num(100 * (v.a - v.c) / t); } },
		retention: { label: 'نرخ نگهداشت', inputs: [['a', 'مشتریان پایان دوره', 180], ['b', 'مشتریان جدید در دوره', 40], ['c', 'مشتریان ابتدای دوره', 170]], out: function (v) { return pct((v.a - v.b) / v.c); } },
		abtest: { label: 'آزمون A/B (z دو نسبت)', inputs: [['a', 'تبدیل A', 48], ['b', 'ارسال A', 1000], ['c', 'تبدیل B', 70], ['d', 'ارسال B', 1000]], out: function (v) {
			var p1 = v.a / v.b, p2 = v.c / v.d, p = (v.a + v.c) / (v.b + v.d), se = Math.sqrt(p * (1 - p) * (1 / v.b + 1 / v.d)), z = (p2 - p1) / se;
			var pv = 2 * (1 - phi(Math.abs(z)));
			return 'A: ' + pct(p1) + ' · B: ' + pct(p2) + ' · z = ' + num(z) + ' · p = ' + num(pv, 4) + (pv < 0.05 ? ' → تفاوت معنادار است (۹۵٪)' : ' → هنوز معنادار نیست؛ ادامه دهید');
		} },
		sample: { label: 'حجم نمونه لازم برای هر گروه A/B', inputs: [['a', 'نرخ تبدیل فعلی (٪)', 5], ['b', 'کمترین بهبود قابل تشخیص (٪ مطلق)', 2]], out: function (v) {
			var p1 = v.a / 100, p2 = (v.a + v.b) / 100, za = 1.96, zb = 0.8416, pb = (p1 + p2) / 2;
			var n = Math.pow(za * Math.sqrt(2 * pb * (1 - pb)) + zb * Math.sqrt(p1 * (1 - p1) + p2 * (1 - p2)), 2) / Math.pow(p2 - p1, 2);
			return num(Math.ceil(n), 0) + ' نفر در هر گروه (اطمینان ۹۵٪، توان ۸۰٪)';
		} },
		score: { label: 'امتیاز سرنخ', inputs: [['a', 'تناسب (۰–۴۰)', 30], ['b', 'علاقه/تعامل (۰–۳۰)', 20], ['c', 'آمادگی مالی (۰–۲۰)', 15], ['d', 'فوریت (۰–۱۰)', 5]], out: function (v) { var s = v.a + v.b + v.c + v.d; return num(s, 0) + ' از ۱۰۰ — ' + (s >= 70 ? 'داغ: امروز تماس' : s >= 40 ? 'گرم: پرورش با محتوا' : 'سرد: فهرست بلندمدت'); } },
		quota: { label: 'فعالیت لازم برای رسیدن به هدف', inputs: [['a', 'هدف فروش ماه', 10], ['b', 'نرخ تبدیل تماس به فروش (٪)', 8], ['c', 'روز کاری ماه', 22]], out: function (v) { var calls = v.a / (v.b / 100); return num(Math.ceil(calls), 0) + ' گفتگوی مؤثر در ماه ≈ ' + num(Math.ceil(calls / v.c), 0) + ' در روز'; } }
	};
	function num(x, d) { if (!isFinite(x)) { return '—'; } return fa((Math.round(x * Math.pow(10, d == null ? 2 : d)) / Math.pow(10, d == null ? 2 : d)).toLocaleString('en-US')); }
	function pct(x) { return num(100 * x) + '٪'; }
	function money(x) { return num(Math.round(x), 0) + ' ریال'; }
	function phi(z) { // standard normal CDF (Abramowitz–Stegun 26.2.17)
		var t = 1 / (1 + 0.2316419 * z), d = 0.3989423 * Math.exp(-z * z / 2);
		return 1 - d * t * (0.3193815 + t * (-0.3565638 + t * (1.781478 + t * (-1.821256 + t * 1.330274))));
	}
	function calculator(box) {
		var c = CALC[box.getAttribute('data-calc')];
		if (!c) { return; }
		box.innerHTML = '';
		box.appendChild(el('h4', {}, [el('i', { class: 'fa fa-calculator' }), ' ماشین‌حساب: ' + c.label]));
		var form = el('div', { class: 'ac-calc-grid' }), outEl = el('output', { class: 'ac-calc-out' });
		c.inputs.forEach(function (inp) {
			var id = 'c' + Math.random().toString(36).slice(2, 8);
			form.appendChild(el('label', { for: id, text: inp[1] }));
			form.appendChild(el('input', { id: id, type: 'number', step: 'any', value: String(inp[2]), 'data-k': inp[0], dir: 'ltr', inputmode: 'decimal' }));
		});
		var run = function () { var v = {}; qa('input', form).forEach(function (i) { v[i.getAttribute('data-k')] = parseFloat(i.value) || 0; }); outEl.textContent = c.out(v); };
		form.addEventListener('input', run);
		box.appendChild(form); box.appendChild(el('div', { class: 'ac-calc-res' }, ['نتیجه: ', outEl]));
		run();
	}

	function enhance(art, id) {
		// checklists
		qa('ul.todo', art).forEach(function (ul, n) {
			var listId = id + ':' + (ul.getAttribute('data-id') || n), state = load('todo:' + listId, {});
			qa('li', ul).forEach(function (li, k) {
				var cb = el('input', { type: 'checkbox', 'aria-label': li.textContent.trim().slice(0, 60) });
				cb.checked = !!state[k];
				li.classList.toggle('checked', cb.checked);
				cb.addEventListener('change', function () { var s = load('todo:' + listId, {}); s[k] = cb.checked; save('todo:' + listId, s); li.classList.toggle('checked', cb.checked); });
				li.insertBefore(cb, li.firstChild);
			});
		});
		// calculators
		qa('[data-calc]', art).forEach(calculator);
		// funnels: <div class="funnel"><div data-v="1000">سرنخ</div>…</div>
		qa('.funnel', art).forEach(function (f) {
			var rows = qa('[data-v]', f), max = Math.max.apply(null, rows.map(function (r) { return parseFloat(r.getAttribute('data-v')); }));
			rows.forEach(function (r, i) {
				var v = parseFloat(r.getAttribute('data-v')), prev = i ? parseFloat(rows[i - 1].getAttribute('data-v')) : null;
				r.style.setProperty('--w', Math.max(28, 100 * v / max) + '%');
				r.appendChild(el('b', { text: fa(v.toLocaleString('en-US')) + (prev ? ' (' + fa(Math.round(1000 * v / prev) / 10) + '٪ از مرحله قبل)' : '') }));
			});
		});
		// zoomable screenshots
		qa('figure.shot img', art).forEach(function (img) {
			img.loading = 'lazy'; img.decoding = 'async';
			img.setAttribute('tabindex', '0'); img.setAttribute('role', 'button'); img.setAttribute('aria-label', 'بزرگ‌نمایی تصویر');
			var openZoom = function () { zoom(img); };
			img.addEventListener('click', openZoom);
			img.addEventListener('keydown', function (e) { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openZoom(); } });
		});
		// tables scroll on phones
		qa('table', art).forEach(function (t) { if (!t.parentNode.classList.contains('ac-tablewrap')) { var w = el('div', { class: 'ac-tablewrap' }); t.parentNode.insertBefore(w, t); w.appendChild(t); } });
	}
	var dlg;
	function zoom(img) {
		if (!dlg) {
			dlg = el('dialog', { class: 'ac-zoom', 'aria-label': 'تصویر بزرگ' }, [el('button', { type: 'button', class: 'ac-zoom-close', 'aria-label': 'بستن' }, [el('i', { class: 'fa fa-times' })]), el('img', { alt: '' })]);
			document.body.appendChild(dlg);
			q('.ac-zoom-close', dlg).addEventListener('click', function () { dlg.close(); });
			dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });
		}
		var big = q('img', dlg);
		big.src = img.src; big.alt = img.alt;
		if (dlg.showModal) { dlg.showModal(); } else { window.open(img.src, '_blank'); }
	}

	/* ------------------------------------------------------------------ start */
	function start() {
		root = q('#samenAcademy');
		if (!root) { return; }
		user = root.getAttribute('data-user') || '0';
		q('.ac-navtoggle', root).addEventListener('click', function () { root.classList.toggle('nav-open'); });
		fetch(BASE + 'courses.json?v=' + VERSION, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (c) {
			catalog = c;
			c.courses.forEach(function (course) { course.lessons.forEach(function (l, n) { flat.push({ id: l[0], title: l[1], course: course, n: n + 1 }); }); });
			sidebar();
			root.addEventListener('click', function (e) {
				var a = e.target.closest && e.target.closest('a[data-lesson]');
				if (!a || e.ctrlKey || e.metaKey || e.shiftKey || e.button) { return; }
				e.preventDefault();
				go(a.getAttribute('data-lesson'), true);
			});
			window.addEventListener('popstate', function () { go(new URLSearchParams(window.location.search).get('lesson') || '', false); });
			go(root.getAttribute('data-lesson') || '', false);
		});
	}
	if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', start); } else { start(); }
})(window, document);
