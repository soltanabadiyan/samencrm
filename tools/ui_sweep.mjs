#!/usr/bin/env node
// Error sweep of the whole CRM in a real browser: opens every page (app menus, every Settings link, list /
// create / detail / edit of every module, extension pages) on desktop and phone and reports per page:
//   JS exceptions, console errors, failed requests (4xx/5xx/network) on the CRM host, PHP/Vtiger error text,
//   horizontal overflow, visible English words left in the Persian UI.
//   SESSION=$(tools/get_session.sh) node tools/ui_sweep.mjs out_dir [extra_urls.json]
// Needs the SOCKS tunnel (ssh -D 19080 hashemz_crm). Screenshots are saved only for pages with problems.
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync, mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [out, extraFile] = process.argv.slice(2);
const BASE = process.env.BASE || 'http://212.33.206.241:9081/';
const HOST = new URL(BASE).host;
mkdirSync(out, { recursive: true });
const VIEW = {
  desktop: { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false },
  mobile: { width: 390, height: 844, deviceScaleFactor: 1, mobile: true },
};
const UA = {
  desktop: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
  mobile: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36',
};
const ERROR_TEXT = /Permission to perform the operation is denied|Fatal error|Uncaught|Exception:|Warning: |Notice: |Deprecated: |Parse error|Call to undefined|SQLSTATE|Error in query|Smarty error|Unable to load template|Invalid request|خطای پایگاه داده/;
// Latin words that are fine in the Persian UI (brand, units, formats, protocol names)
const ALLOWED_EN = /^(SamenCRM|samen|CRM|PBX|RSS|SMS|URL|API|ID|PDF|CSV|Excel|vCard|ON|OFF|KB|MB|GB|Google|Telegram|WhatsApp|Bot|HTML|IMAP|SMTP|SSL|TLS|PHP|Vtiger|vtiger|OAuth|Webhook|CKEditor|Jalali|admin|fa_ir|en_us|Asia|Tehran|yyyy|mm|dd|AM|PM|USD|IRR|IRT|ok|OK|Email|ext|www|http|https|com|ir|tg|wa|me|org|net)$/i;

const port = 9300 + Math.floor(Math.random() * 500);
const chrome = spawn('google-chrome', ['--headless=new', `--remote-debugging-port=${port}`, '--no-first-run',
  `--user-data-dir=${mkdtempSync(join(tmpdir(), 'uisweep-'))}`, `--proxy-server=${process.env.PROXY || 'socks5://127.0.0.1:19080'}`,
  'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ws, id = 0; const pending = new Map(), waiters = [];
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const once = method => new Promise(resolve => waiters.push({ method, resolve }));
let cur = null; // problems of the page being visited
const reqUrl = new Map();

async function connect() {
  for (let i = 0; i < 60; i++) {
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
    if (el.closest('.listViewEntries td.listViewEntryValue, .value, .fieldValue, .summaryViewEntries .fieldValue, .listview-table td:not(:first-child), .commentInfoContent, .historyContainer, .updates_timeline, .tg-msg, .samen-ext-table td')) continue;
    for (const m of n.nodeValue.matchAll(/[A-Za-z][A-Za-z']{2,}/g)) words.add(m[0]);
  }
  return {
    title: document.title,
    url: location.href,
    err: (txt.match(${ERROR_TEXT}) || [''])[0],
    overflow: document.documentElement.scrollWidth - window.innerWidth,
    en: [...words],
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
  res.menuJs = info.menuJs;
  res.bad = !!(res.js.length || res.console.length || res.http.length || res.err || res.overflow || (res.final.startsWith('index.php') && !/view=Login/.test(res.final) && !res.menuJs && !/action=|mode=|view=(Popup|ComposeEmail)/.test(url)));
  if (res.bad || res.en.length) {
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
  console.log(`sweeping ${urls.length} pages (+${mobileUrls.size} on phone)`);
  for (const u of urls) {
    results.push(await visit(u, 'desktop'));
    if (mobileUrls.has(u)) results.push(await visit(u, 'mobile'));
    const r = results[results.length - 1];
    if (r.bad || r.en.length) console.log(JSON.stringify({ u, vp: r.vp, final: r.final !== u ? r.final : undefined, js: r.js, console: r.console, http: r.http, err: r.err, overflow: r.overflow, en: r.en, menuJs: r.menuJs, shot: r.shot }));
  }
  writeFileSync(join(out, 'sweep.json'), JSON.stringify(results, null, 1));
  const bad = results.filter(r => r.bad), en = results.filter(r => r.en.length);
  console.log(`\n${results.length} page loads: ${bad.length} with errors, ${en.length} with English words; details in ${join(out, 'sweep.json')}`);
} finally {
  try { ws?.close(); } catch {}
  chrome.kill();
}
