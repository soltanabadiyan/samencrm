// Runs inside the CRM page before each academy screenshot (loaded by academy_shots.mjs).
// __acMask(): replaces every personal value with invented sample data; __acMark(marks): numbered red markers.

window.__acMask = function () {
  // 1) collect every personal value on the page, 2) replace each of them EVERYWHERE (text, inputs, attributes)
  const NAMES = ['آرمان نمونه','نیلوفر آزمون','کیان نمونه‌پور','ترانه آزموده','رادین نمونه‌زاده','آوا آزمونی','سپهر نمونه‌فر','نگار آزمون‌پور'];
  const FIRST = ['آرمان','نیلوفر','کیان','ترانه','رادین','آوا','سپهر','نگار','بردیا','ستاره','پارسا','یاسمن'];
  const LAST = ['نمونه','آزمون','نمونه‌پور','آزموده','نمونه‌زاده','آزمونی','نمونه‌فر','آزمون‌پور'];
  let k = 0; const pick = a => a[(k++) % a.length];
  const PERSONAL = /نام|پاسپورت|تلفن|موبایل|همراه|ایمیل|واتساپ|پدر|نشانی|آدرس|خیابان|کد پیگیری|سااورگ|شماره|یادداشت|توضیحات|تاریخ تولد|رسید|معقب|نماینده|کد فهرست|عنوان فهرست|منبع|فهرست ها|گروه های منبع|لینک|مسئول|دستیار/;
  const SAFE = /^(--|—|-|بله|خیر|دکتری|کارشناسی|کارشناسی ارشد|کاردانی|سایر|مرد|زن|عراق|افغانستان|سوریه|ایران|فعال|مدیر سامانه|مقطع - .*|دانشجوی .*)$/;
  const STOP = /^(ویرایش|افزودن|مخاطب|مخاطبین|دانشجو|دانشجوی|همه|نمونه|جزئیات|اطلاعات|مقطع|دانشگاه|رشته|برای|جستجو|تایپ|کنید|انتخاب|گزینه|یک)$/;
  const clean = t => (t || '').replace(/[\u200c*:]/g, ' ').replace(/\s+/g, ' ').trim();
  const fakeFor = (label, text) => {
    if (/@/.test(text)) return 'customer' + (k++ % 90 + 10) + '@example.com';
    if (/wa\.me|https?:/.test(text)) return 'https://wa.me/9647700000000';
    if (/نام خانوادگی/.test(label)) return pick(LAST);
    if (/^نام$/.test(label)) return pick(FIRST);
    if (/پدر/.test(label)) return pick(FIRST);
    if (/پاسپورت/.test(label) || /^[A-Z]\d{6,}$/.test(text)) return 'A' + String(10000000 + (k++ * 7919) % 89999999);
    if (/تلفن|موبایل|همراه|واتساپ/.test(label) || /^[+\d\s()-]{7,}$/.test(text)) return '+964 770 ' + String(1000000 + (k++ * 3571) % 8999999);
    if (/\d{4,}/.test(text)) return text.replace(/\d/g, d => String((+d + 3) % 10));
    if (/نام|مسئول|دستیار|معقب|نماینده/.test(label)) return pick(NAMES);
    return 'نمونه';
  };
  const map = new Map(), labels = new Map();
  const add = (label, text) => {
    text = (text || '').trim();
    if (!text || text.length < 2 || SAFE.test(text) || labels.has(text)) return;
    if (/^\d{4}\/\d{2}\/\d{2}|^[۰-۹]{4}\//.test(text) && !/تولد/.test(label)) return;   // dates other than birthdays are not personal
    labels.set(text, label);
    if (/^(نام|نام خانوادگی|نام کامل.*|نام پدر|نام مخاطب)$/.test(label)) text.split(/\s+/).filter(w => w.length > 2 && !STOP.test(w)).forEach(w => { if (!labels.has(w) && !SAFE.test(w)) labels.set(w, 'نام'); });
  };
  const assign = () => {
    const orig = [...labels.keys()];
    const clash = v => orig.some(o => o.indexOf(v) > -1 || v.indexOf(o) > -1);
    labels.forEach((label, text) => {
      let v, tries = 0;
      do { v = fakeFor(label, text); tries++; } while (clash(v) && tries < 40);
      map.set(text, clash(v) ? 'نمونه' : v);
    });
  };
  // list tables: header labels come from whichever table has them (Vtiger clones an empty sticky header)
  const tables = [...document.querySelectorAll('table.listview-table, #listview-table, table.listViewEntriesTable')];
  let heads = [];
  tables.forEach(t => { const h = [...t.querySelectorAll('thead tr:first-child th')].map(th => clean(th.textContent)); if (h.filter(Boolean).length > heads.filter(Boolean).length) heads = h; });
  // reports / templates / workflows lists hold names of reports etc., not of people
  if (!/module=(Reports|EmailTemplates|Workflows)\b/.test(location.search)) tables.forEach(t => t.querySelectorAll('tr.listViewEntries').forEach(tr => [...tr.children].forEach((td, i) => { const l = heads[i] || ''; if (PERSONAL.test(l)) add(l, td.textContent); })));
  // detail / summary / edit fields
  document.querySelectorAll('.fieldLabel').forEach(lab => {
    const l = clean(lab.textContent); if (!PERSONAL.test(l)) return;
    const val = lab.nextElementSibling; if (!val) return;
    // edit forms: only the typed values (the cell also holds placeholders like «برای جستجو تایپ کنید» and option lists)
    if (!val.querySelector('input:not([type=hidden]), textarea, select')) add(l, val.textContent);
    val.querySelectorAll('input, textarea').forEach(inp => { if (inp.type !== 'hidden' && inp.type !== 'checkbox' && inp.type !== 'radio') add(l, inp.value); });
  });
  // record titles
  // record titles name a person only on existing contact / lead records (not on blank create forms or tasks)
  const personRecord = /module=(Contacts|Leads|Accounts)\b/.test(location.search) && /record=\d/.test(location.search);
  if (personRecord) document.querySelectorAll('.recordLabel, .editViewHeader h4, .editViewPageDiv > h4, .editViewContents h4.editHeader').forEach(e => {
    const t = clean(e.textContent).replace(/^ویرایش[^-–]*[-–]\s*/, ''); add('نام کامل', t);
  });
  if (personRecord) document.querySelectorAll('.module-breadcrumb p:last-of-type a').forEach(e => add('نام کامل', clean(e.textContent).replace(/^ویرایش\s*:\s*/, '')));
  assign();
  // replace everywhere
  const keys = [...map.keys()].sort((a, b) => b.length - a.length);
  const LETTER = /[\u0600-\u06FF\w]/;
  const swap = t => {
    keys.forEach(key => {
      let i = t.indexOf(key);
      while (i > -1) {
        const before = t[i - 1] || ' ', after = t[i + key.length] || ' ';
        if (!LETTER.test(before) && !LETTER.test(after)) { t = t.slice(0, i) + map.get(key) + t.slice(i + key.length); i = t.indexOf(key, i + map.get(key).length); }
        else { i = t.indexOf(key, i + 1); }
      }
    });
    return t;
  };
  const UI = '.fieldLabel, th, label, h4.blockHeader, .blockHeader, .block > div > h4, button, .btn, .nav, .app-footer, .module-title, .related-tabs, .lists-menu, .sidebar-essentials, .app-menu, .modal-header, .editViewContents .block h4, .settingsgroup, .sh-panel';
  const re = keys.length > 0;
  if (re) {
    const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n; const list = [];
    while ((n = w.nextNode())) list.push(n);
    list.forEach(t => { if (t.parentElement && t.parentElement.closest(UI)) return; const v = swap(t.nodeValue); if (v !== t.nodeValue) t.nodeValue = v; });
    document.querySelectorAll('input, textarea').forEach(i => { if (i.value) i.value = swap(i.value); });
    document.querySelectorAll('[title], [alt], [placeholder], [data-original-title]').forEach(e => ['title', 'alt', 'data-original-title'].forEach(a => { if (e.getAttribute(a)) e.setAttribute(a, swap(e.getAttribute(a))); }));
  }
  // remaining long free texts (notes, descriptions, comments, imported provenance) and anything that looks like a phone / e-mail / passport
  document.querySelectorAll('.commentInfoContent, .update_info .field-name + span, .history-content, .tg-msg .tg-body').forEach(e => { e.textContent = 'متن نمونه'; });
  const w2 = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n2;
  while ((n2 = w2.nextNode())) {
    if (n2.parentElement && n2.parentElement.closest('.sh-panel, .sh-tip, .ac-main')) continue;   // our own help texts (fictitious examples)
    let v = n2.nodeValue;
    v = v.replace(/[\w.+-]+@[\w-]+\.[\w.]+/g, 'customer@example.com').replace(/\+\d[\d\s-]{8,}\d|\b\d{9,}\b/g, '+964 770 000 0000').replace(/\b[A-Z]\d{7,8}\b/g, 'A12345678');
    if (v !== n2.nodeValue) n2.nodeValue = v;
  }
  document.querySelectorAll('.tag .tagLabel, .tag-item').forEach(t => { if (!/مقطع|رشته|دانشگاه/.test(t.textContent)) t.textContent = 'برچسب نمونه'; });
  window.__acMasked = map.size;
  window.__acOrig = keys.filter(x => x.length > 3 && !/^\d+$/.test(x));
};
window.__acMark = function (marks) {
  document.querySelectorAll('.ac-mk').forEach(e => e.remove());
  const missing = [];
  const visible = e => { const r = e.getBoundingClientRect(); return r.width > 2 && r.height > 2; };
  const clean = t => (t || '').replace(/[\u200c*:]/g, ' ').replace(/\s+/g, ' ').trim();
  const find = m => {
    if (typeof m === 'string') return [[...document.querySelectorAll(m)].filter(visible)[0]];
    if (m.label) {                                    // a form / detail field: label cell + value cell
      const lab = [...document.querySelectorAll('.fieldLabel')].filter(visible).find(e => clean(e.textContent) === clean(m.label))
        || [...document.querySelectorAll('.fieldLabel')].filter(visible).find(e => clean(e.textContent).indexOf(clean(m.label)) === 0);
      return lab ? [lab, m.only ? null : lab.nextElementSibling].filter(Boolean) : [];
    }
    if (m.text) {                                     // smallest visible element (of sel) whose text contains m.text
      const c = [...document.querySelectorAll(m.sel || 'a,button,li,label,span,th,h3,h4,div')].filter(visible).filter(e => clean(e.textContent).indexOf(clean(m.text)) > -1);
      c.sort((a, b) => a.textContent.length - b.textContent.length);
      return [c[m.nth || 0]];
    }
    return [[...document.querySelectorAll(m.sel)].filter(visible)[m.nth || 0]];
  };
  marks.forEach((m, i) => {
    const els = find(m).filter(Boolean);
    if (!els.length) { missing.push(JSON.stringify(m)); return; }
    const rs = els.map(e => e.getBoundingClientRect());
    const r = { left: Math.min(...rs.map(x => x.left)), top: Math.min(...rs.map(x => x.top)), right: Math.max(...rs.map(x => x.right)), bottom: Math.max(...rs.map(x => x.bottom)) };
    r.left = Math.max(2, r.left); r.top = Math.max(2, r.top); r.right = Math.min(window.innerWidth - 2, r.right); r.bottom = Math.min(window.innerHeight - 2, r.bottom);
    const pad = 3, box = document.createElement('div'); box.className = 'ac-mk';
    Object.assign(box.style, { position: 'fixed', left: (r.left - pad) + 'px', top: (r.top - pad) + 'px', width: (r.right - r.left + 2 * pad) + 'px', height: (r.bottom - r.top + 2 * pad) + 'px',
      border: '3px solid #E11D48', borderRadius: '8px', zIndex: 2147483646, pointerEvents: 'none', boxShadow: '0 0 0 3px rgba(225,29,72,.18)' });
    const b = document.createElement('div'); b.className = 'ac-mk'; b.textContent = String(i + 1);
    const bx = Math.min(window.innerWidth - 30, Math.max(4, r.right - 10)), by = Math.max(4, r.top - 14);
    Object.assign(b.style, { position: 'fixed', left: bx + 'px', top: by + 'px', width: '26px', height: '26px', borderRadius: '50%', background: '#E11D48', color: '#fff',
      font: '800 14px/26px Tahoma, sans-serif', textAlign: 'center', zIndex: 2147483647, pointerEvents: 'none', boxShadow: '0 2px 6px rgba(0,0,0,.35)' });
    document.body.appendChild(box); document.body.appendChild(b);
  });
  return missing;
};
// originals still visible after masking (text or form values) – the screenshot is refused if any remain
window.__acLeaks = function () {
  // the filter / tag sidebar only holds admin-made list names (level, university, major) – not personal data
  // (and <select> option lists: never visible in a screenshot – an open drop-down is rendered by select2 markup)
  const side = [...document.querySelectorAll('.sidebar-essentials, select')];
  side.forEach(e => { e.dataset.acDisp = e.style.display; e.style.display = 'none'; });
  const text = document.body.innerText + ' ' + [...document.querySelectorAll('input, textarea')].map(i => i.value).join(' ');
  side.forEach(e => { e.style.display = e.dataset.acDisp || ''; });
  const left = (window.__acOrig || []).filter(o => text.indexOf(o) > -1);
  const pat = text.match(/[\w.+-]+@(?!example\.com)[\w-]+\.[a-z]{2,}/gi) || [];   // any real-looking e-mail
  return left.concat(pat.filter(e => !/samenstudent\.local$/i.test(e)));
};

// __acGeneric(): SamenCRM is a general CRM – the screenshots must not show the education fields of this installation.
// Runs after __acMask (which still needs the original words to know what is not personal); replaces field names,
// values, list / tag / group names with general business wording everywhere on the page.
window.__acGeneric = function () {
  let k = 0; const pick = a => a[(k++) % a.length];
  const TITLES = ['مدیر خرید', 'کارشناس فروش', 'مدیرعامل', 'مسئول بازرگانی', 'مدیر مالی'];
  const TIER = { 'کارشناسی ارشد': 'نقره‌ای', 'دکتری': 'طلایی', 'کارشناسی': 'برنزی', 'کاردانی': 'عادی', 'سایر': 'سایر', 'نامشخص': 'نامشخص' };
  const PRODUCTS = ['بسته پایه', 'بسته حرفه‌ای', 'بسته سازمانی', 'پشتیبانی سالانه', 'آموزش و راه‌اندازی'];
  const BRANCHES = ['شعبه تهران', 'شعبه اصفهان', 'شعبه شیراز', 'شعبه مشهد', 'شعبه تبریز'];
  const tier = v => TIER[v.trim()] || pick(['طلایی', 'نقره‌ای', 'برنزی']);
  // values of the education fields (list columns, detail / edit fields) first, while their labels are still recognisable
  const FIELD = [[/^(مقطع|مقطع \(مطابق فایل\)|کد مقطع)$/, v => tier(v)], [/^(رشته|کد رشته)$/, () => pick(PRODUCTS)],
    [/^(دانشگاه|دانشگاه \(مطابق فایل\))$/, () => pick(BRANCHES)], [/^(معدل)$/, () => pick(['۸۵', '۷۲', '۹۰'])], [/^(دپارتمان|دانشکده|گرایش)$/, () => pick(['فروش', 'بازرگانی', 'مالی', 'فنی'])], [/^سمت$/, null]];
  const clean = t => (t || '').replace(/[‌*:]/g, ' ').replace(/\s+/g, ' ').trim();
  const fieldFn = l => { const f = FIELD.find(x => x[0].test(l)); return f ? f[1] : undefined; };
  const setVal = (cell, fn) => {
    cell.querySelectorAll('input:not([type=hidden]):not([type=checkbox]), textarea').forEach(i => { if (i.value) i.value = fn(i.value); });
    const w = document.createTreeWalker(cell, NodeFilter.SHOW_TEXT); let n;
    while ((n = w.nextNode())) { if (n.nodeValue.trim() && !(n.parentElement && n.parentElement.closest('option, script, style'))) n.nodeValue = n.nodeValue.replace(n.nodeValue.trim(), fn(n.nodeValue)); }
  };
  document.querySelectorAll('table').forEach(t => {
    const heads = [...t.querySelectorAll('thead tr:first-child th')].map(th => clean(th.textContent));
    t.querySelectorAll('tr.listViewEntries').forEach(tr => [...tr.children].forEach((td, i) => { const fn = fieldFn(heads[i] || ''); if (fn) setVal(td, fn); }));
  });
  document.querySelectorAll('.fieldLabel').forEach(lab => {
    const fn = fieldFn(clean(lab.textContent)); const val = lab.nextElementSibling;
    if (fn && val) setVal(val, fn);
  });
  // then words anywhere (labels, block titles, filter / tag / group names, options, attributes)
  const RULES = [
    [/دانشجوی\s+(کارشناسی ارشد|دکتری|کارشناسی|کاردانی|سایر)/g, () => pick(TITLES)],
    [/مشخصات دانشجو/g, 'مشخصات مشتری'], [/شماره دانشجویی/g, 'شماره مشتری'], [/وضعیت دانشجو/g, 'وضعیت مشتری'],
    [/دانشجویان/g, 'مشتریان'], [/دانشجوی/g, 'مشتری'], [/دانشجو/g, 'مشتری'],
    [/مقطع\s*([:\-–])\s*(کارشناسی ارشد|دکتری|کارشناسی|کاردانی|سایر|نامشخص)/g, (m, s, v) => 'گروه مشتری ' + s + ' ' + TIER[v]],
    [/رشته\s*:\s*[^\n،|]+/g, () => 'علاقه: ' + pick(PRODUCTS)],
    [/دانشگاه‌های شهریه‌ای|دانشگاه های شهریه ای/g, 'خدمات ویژه'], [/خدمات دانشگاه/g, 'خدمات جانبی'],
    [/دانشگاه\s*:\s*[^\n،|]+/g, () => 'شعبه: ' + pick(BRANCHES).replace('شعبه ', '')],
    [/دانشگاه(\s+(صنعتی|آزاد|علوم پزشکی|بین‌المللی))?\s+([آ-ی]+)/g, (m, a, b, c) => 'شعبه ' + c], [/دانشگاه/g, 'شعبه'],
    [/کد سااورگ/g, 'کد اشتراک'], [/سااورگ/g, 'اشتراک'], [/کد رشته/g, 'کد محصول'], [/کد مقطع/g, 'کد گروه'],
    [/مقطع/g, 'گروه مشتری'], [/رشته/g, 'محصول موردعلاقه'],
    [/ارزشیابی معدل/g, 'ارزیابی'], [/معدل/g, 'امتیاز'], [/ترم ورود/g, 'دوره شروع'], [/شهر ویزا/g, 'شهر خدمت'], [/ویزا/g, 'خدمات اداری'],
    [/اشتغال به تحصیل/g, 'گواهی'], [/شیوه تحصیل/g, 'شیوه ارائه خدمت'], [/تحصیل/g, 'خدمت'], [/شهریه/g, 'قرارداد'],
    [/وضعیت پذیرش/g, 'وضعیت قرارداد'], [/پذیرش اولیه/g, 'قرارداد اولیه'], [/پذیرش/g, 'قرارداد'],
    [/فارغ‌التحصیل\S*/g, 'مشتری قدیمی'], [/خوابگاه/g, 'خدمات جانبی'],
    [/samenstudent/gi, 'samencrm'],
    [/کد دانشکده/g, 'کد واحد'], [/دانشکده/g, 'واحد'], [/گرایش/g, 'نوع خدمت'], [/آیین‌نامه آموزشی/g, 'نوع قرارداد'], [/آموزش عالی/g, 'خدمات'],
    [/^\s*(کارشناسی ارشد|دکتری|کارشناسی|کاردانی)\s*$/g, (m, v) => m.replace(v, TIER[v])],
  ];
  const swap = v => RULES.reduce((s, r) => s.replace(r[0], r[1]), v);
  const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let n;
  while ((n = w.nextNode())) { if (n.parentElement && n.parentElement.closest('script, style')) continue; const v = swap(n.nodeValue); if (v !== n.nodeValue) n.nodeValue = v; }
  document.querySelectorAll('input:not([type=hidden]), textarea').forEach(i => { if (i.value) i.value = swap(i.value); });
  document.querySelectorAll('[title], [alt], [placeholder], [data-original-title]').forEach(e => ['title', 'alt', 'placeholder', 'data-original-title'].forEach(a => { if (e.getAttribute(a)) e.setAttribute(a, swap(e.getAttribute(a))); }));
};
// education words still visible after __acGeneric (a screenshot with any of them is not captured)
window.__acDomainLeft = function () {
  const text = document.body.innerText + ' ' + [...document.querySelectorAll('input:not([type=hidden]), textarea')].map(i => i.value).join(' ');
  return [...new Set(text.match(/.{0,25}(دانشجو|دانشگاه|دانشکده|گرایش|آیین‌نامه آموزشی|مقطع|رشته|سااورگ|معدل|ویزا|شهریه|پذیرش|تحصیل|خوابگاه).{0,25}/g) || [])].slice(0, 8);
};
