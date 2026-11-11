#!/usr/bin/env node
// Error sweep of the whole CRM in a real browser: opens every page (app menus, every Settings link, list /
// create / detail / edit of every module, extension pages) on desktop and phone and reports per page:
//   JS exceptions, console errors, failed requests (4xx/5xx/network) on the CRM host, PHP/Vtiger error text,
//   horizontal overflow, visible English words left in the Persian UI, any visible trace of Vtiger (text, title,
//   image, tooltip, link to vtiger.com).
//   SESSION=$(tools/get_session.sh) node tools/ui_sweep.mjs out_dir [extra_urls.json]
// Needs the SOCKS tunnel (ssh -D 19080 hashemz_crm). Screenshots are saved only for pages with problems.
import { spawn } from 'node:child_process';
import { rmSync, mkdirSync, writeFileSync, mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [out, extraFile] = process.argv.slice(2);
const BASE = process.env.BASE || 'http://212.33.206.241:9081/';
const HOST = new URL(BASE).host;
mkdirSync(out, { recursive: true });
const VIEW = {
  desktop: { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false },
  laptop: { width: 1280, height: 800, deviceScaleFactor: 1, mobile: false },
  tablet: { width: 768, height: 1024, deviceScaleFactor: 1, mobile: true },
  mobile: { width: 390, height: 844, deviceScaleFactor: 1, mobile: true },
};
// SWEEP_VIEWPORTS=desktop,laptop,tablet,mobile  – viewports for the pages marked "mobile" in the page list
const EXTRA_VP = (process.env.SWEEP_VIEWPORTS || 'mobile').split(',').filter(v => VIEW[v]);
const UA = {
  desktop: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
  laptop: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
  tablet: 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
  mobile: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36',
};
const ERROR_TEXT = /Permission to perform the operation is denied|Fatal error|Uncaught|Exception:|Warning: |Notice: |Deprecated: |Parse error|Call to undefined|SQLSTATE|Error in query|Smarty error|Unable to load template|Invalid request|خطای پایگاه داده/;
// Latin words that are fine in the Persian UI (brand, units, formats, protocol names)
const ALLOWED_EN = /^(SamenCRM|samen|CRM|PBX|RSS|SMS|URL|API|ID|PDF|CSV|Excel|vCard|ON|OFF|KB|MB|GB|Google|Telegram|WhatsApp|Bot|HTML|IMAP|SMTP|SSL|TLS|PHP|OAuth|Webhook|CKEditor|Jalali|admin|fa_ir|en_us|Asia|Tehran|yyyy|mm|dd|AM|PM|USD|IRR|IRT|ok|OK|Email|ext|www|http|https|com|ir|tg|wa|me|org|net)$/i;

const PROFILE = mkdtempSync(join(tmpdir(), 'uisweep-'));   // removed again when the run ends
const port = 9300 + Math.floor(Math.random() * 500);
const chrome = spawn('google-chrome', ['--headless=new', `--remote-debugging-port=${port}`, '--no-first-run',
  `--user-data-dir=${PROFILE}`, `--proxy-server=${process.env.PROXY || 'socks5://127.0.0.1:19080'}`,
  'about:blank'], { stdio: 'ignore' });
chrome.on('exit', () => { try { rmSync(PROFILE, { recursive: true, force: true }); } catch {} });   // no profiles left in /tmp
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ws, id = 0; const pending = new Map(), waiters = [];
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const once = method => new Promise(resolve => waiters.push({ method, resolve }));
let cur = null; // problems of the page being visited
const reqUrl = new Map();

async function connect() {
  for (let i = 0; i < 300; i++) {   // up to 60 s: Chrome starts slowly on a busy machine
    try {
      const page = (await (await fetch(`http://127.0.0.1:${port}/json/list`)).json()).find(t => t.type === 'page');
      if (page) { ws = new WebSocket(page.webSocketDebuggerUrl); break; }
    } catch { /* starting */ }
    await sleep(200);
  }
  await new Promise(r => ws.addEventListener('open', r));
  ws.addEventListener('message', e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
    if (m.method) waiters.splice(0).forEach(w => (w.method === m.method ? w.resolve(m) : waiters.push(w)));
    if (!cur) return;
    const p = m.params || {};
    if (m.method === 'Runtime.exceptionThrown') cur.js.push((p.exceptionDetails?.exception?.description || p.exceptionDetails?.text || '').split('\n')[0]);
    if (m.method === 'Runtime.consoleAPICalled' && p.type === 'error') cur.console.push(p.args.map(a => a.value ?? a.description ?? '').join(' ').slice(0, 200));
    if (m.method === 'Network.requestWillBeSent') reqUrl.set(p.requestId, p.request.url);
    if (m.method === 'Network.responseReceived' && p.response.status >= 400 && p.response.url.includes(HOST)) cur.http.push(`${p.response.status} ${p.response.url.replace(BASE, '')}`);
    if (m.method === 'Network.loadingFailed' && !p.canceled && (reqUrl.get(p.requestId) || '').includes(HOST)) cur.http.push(`FAILED ${p.errorText} ${(reqUrl.get(p.requestId) || '').replace(BASE, '')}`);
  });
}

const PROBE = `(() => {
  const txt = document.body ? document.body.innerText : '';
  const vis = el => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none'; };
  const words = new Set();
  const w = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let n;
  while ((n = w.nextNode())) {
    const el = n.parentElement;
    if (!el || el.closest('script,style,noscript,code,pre,textarea,[dir=ltr],.select2-offscreen,.hide,.ltr,input,option,.CodeMirror') || !vis(el)) continue;
    // skip record data (values the users typed / imported), only check the UI chrome
    // (Settings pages show configuration, not student records – their tables are checked too)
    if (!/parent=Settings/.test(location.search) && el.closest('.listViewEntries td.listViewEntryValue, .value, .fieldValue, .summaryViewEntries .fieldValue, .listview-table td:not(:first-child), .commentInfoContent, .historyContainer, .tg-msg, .samen-ext-table td')) continue;
    for (const m of n.nodeValue.matchAll(/[A-Za-z][A-Za-z']{2,}/g)) words.add(m[0]);
  }
  return {
    title: document.title,
    url: location.href,
    err: (txt.match(${ERROR_TEXT}) || [''])[0],
    overflow: document.documentElement.scrollWidth - window.innerWidth,
    en: [...words],
    // the product is SamenCRM: no visible "vtiger" anywhere (text in any direction, title, images, tooltips, links)
    vt: (() => {
      const hits = new Set(); const re = /v\s?tiger/i;
      if (re.test(document.title)) hits.add('title: ' + document.title);
      const tw = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT); let t;
      while ((t = tw.nextNode())) { const el = t.parentElement;
        if (el && !el.closest('script,style,noscript') && vis(el) && re.test(t.nodeValue)) hits.add('text: ' + t.nodeValue.trim().slice(0, 90)); }
      document.querySelectorAll('img,[title],[alt],[placeholder],a[href],input[type=submit],input[type=button],option').forEach(el => {
        if (el.tagName !== 'OPTION' && !vis(el)) return;
        const a = (el.tagName === 'IMG' ? el.getAttribute('src') : '') + ' ' + (el.getAttribute('title') || '') + ' ' + (el.getAttribute('alt') || '') + ' '
          + (el.getAttribute('placeholder') || '') + ' ' + (el.tagName === 'A' && /vtiger\.com/i.test(el.href) ? el.href : '') + ' '
          + (/^(INPUT|OPTION)$/.test(el.tagName) ? el.value + ' ' + el.textContent : '');
        if (re.test(a)) hits.add(el.tagName.toLowerCase() + ': ' + a.trim().slice(0, 90));
      });
      return [...hits].slice(0, 15);
    })(),
    // empty band between the content and the right edge / module rail (RTL), or the left edge
    gap: (() => {
      const ca = [...document.querySelectorAll('.main-container .content-area, .main-container .settingsPageDiv')].find(vis);
      if (!ca) return null;
      const b = ca.getBoundingClientRect(), s = getComputedStyle(ca);
      const rail = [...document.querySelectorAll('.main-container .module-nav, .settingsgroup, .sidebar-essentials')].filter(vis)
        .map(e => e.getBoundingClientRect()).filter(r => r.left > innerWidth / 2).reduce((m, r) => Math.min(m, r.left), document.documentElement.clientWidth);
      const side = [...document.querySelectorAll('.main-container .module-nav, .settingsgroup, .sidebar-essentials')].filter(vis)
        .map(e => e.getBoundingClientRect()).filter(r => r.left > innerWidth / 2).reduce((m, r) => Math.max(m, r.right), 0);
      return { right: Math.round(rail - (b.right - parseFloat(s.paddingRight))), left: Math.round(b.left + parseFloat(s.paddingLeft)), side: Math.round(side) };
    })(),
    menuJs: !!(window.jQuery && jQuery._data(document.querySelector('.app-icon') || document, 'events')?.click),
  };
})()`;

async function visit(url, vp) {
  cur = { url, vp, js: [], console: [], http: [] };
  await send('Emulation.setDeviceMetricsOverride', VIEW[vp]);
  await send('Emulation.setUserAgentOverride', { userAgent: UA[vp] });
  const loaded = once('Page.loadEventFired');
  await send('Page.navigate', { url: BASE + url });
  await Promise.race([loaded, sleep(30000)]);
  await sleep(2500);
  const r = await send('Runtime.evaluate', { expression: PROBE, returnByValue: true });
  const info = r.result?.result?.value || {};
  const res = cur; cur = null;
  res.final = (info.url || '').replace(BASE, '');
  res.err = info.err;
  res.overflow = info.overflow > 2 ? info.overflow : 0;
  res.en = (info.en || []).filter(x => !ALLOWED_EN.test(x));
  res.vt = info.vt || [];
  res.menuJs = info.menuJs;
  res.gap = info.gap;
  res.gapBad = !!(info.gap && (info.gap.right > 60 || info.gap.left > 80));
  res.bad = !!(res.js.length || res.console.length || res.http.length || res.err || res.overflow || res.gapBad || (res.final.startsWith('index.php') && !/view=Login/.test(res.final) && !res.menuJs && !/action=|mode=|view=(Popup|ComposeEmail)/.test(url)));
  if (res.bad || res.en.length || res.vt.length) {
    const shot = await send('Page.captureScreenshot', { format: 'png' });
    res.shot = `${String(results.length).padStart(3, '0')}.${vp}.png`;
    writeFileSync(join(out, res.shot), Buffer.from(shot.result.data, 'base64'));
  }
  return res;
}

const results = [];
try {
  await connect();
  await send('Page.enable'); await send('Network.enable'); await send('Runtime.enable');
  await send('Network.setCookie', { name: 'PHPSESSID', value: process.env.SESSION, url: BASE });
  // collect every menu + settings link from the app itself
  const links = new Set();
  for (const page of ['index.php?module=Contacts&view=List', 'index.php?module=Vtiger&parent=Settings&view=Index']) {
    const loaded = once('Page.loadEventFired');
    await send('Page.navigate', { url: BASE + page });
    await Promise.race([loaded, sleep(30000)]); await sleep(2500);
    const r = await send('Runtime.evaluate', { expression: `[...document.querySelectorAll('#app-menu a[href], .app-modules-dropdown a[href], .settingsgroup a[href], .settingsNavigation a[href]')].map(a=>a.getAttribute('href')).filter(h=>h&&h.startsWith('index.php'))`, returnByValue: true });
    (r.result?.result?.value || []).forEach(h => links.add(h));
  }
  const extra = extraFile ? JSON.parse(readFileSync(extraFile, 'utf8')) : [];
  const urls = [...new Set([...extra.map(e => e.url || e), ...links])];
  const mobileUrls = new Set(extra.filter(e => e.mobile).map(e => e.url));
  console.log(`sweeping ${urls.length} pages (+${mobileUrls.size} x ${EXTRA_VP.join('/')})`);
  for (const u of urls) {
    results.push(await visit(u, 'desktop'));
    if (mobileUrls.has(u)) for (const vp of EXTRA_VP) results.push(await visit(u, vp));
    const r = results[results.length - 1];
    if (r.bad || r.en.length || r.vt.length) console.log(JSON.stringify({ u, vp: r.vp, vt: r.vt.length ? r.vt : undefined, final: r.final !== u ? r.final : undefined, js: r.js, console: r.console, http: r.http, err: r.err, overflow: r.overflow, gap: r.gapBad ? r.gap : undefined, en: r.en, menuJs: r.menuJs, shot: r.shot }));
  }
  writeFileSync(join(out, 'sweep.json'), JSON.stringify(results, null, 1));
  const bad = results.filter(r => r.bad), en = results.filter(r => r.en.length), vtp = results.filter(r => r.vt.length);
  console.log(`\n${results.length} page loads: ${bad.length} with errors, ${en.length} with English words, ${vtp.length} with Vtiger traces; details in ${join(out, 'sweep.json')}`);
} finally {
  try { ws?.close(); } catch {}
  chrome.kill();
}
