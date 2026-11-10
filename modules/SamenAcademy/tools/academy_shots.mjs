#!/usr/bin/env node
// Annotated screenshots for the academy lessons (resources/content/img/<id>.webp).
// For every shot: open the page, run the setup script, REPLACE ALL PERSONAL DATA with invented sample values
// (names, phones, e-mails, passports, notes – nothing of the real students leaves the server), draw numbered
// red markers on the given elements, capture.  Shots are listed in tools/shots.json.
//   SESSION=$(tools/get_session.sh) node modules/SamenAcademy/tools/academy_shots.mjs [filter]
// Needs the SOCKS tunnel (ssh -D 19080 hashemz_crm), like tools/ui_audit.mjs.
import { spawn } from 'node:child_process';
import { rmSync, mkdirSync, writeFileSync, mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = join(HERE, '../layouts/v7/modules/SamenAcademy/resources/content/img');
const BASE = process.env.BASE || 'http://212.33.206.241:9081/';
const filter = process.argv[2];
const shots = JSON.parse(readFileSync(join(HERE, 'shots.json'), 'utf8')).filter(s => !filter || s.id.includes(filter));
mkdirSync(OUT, { recursive: true });
const VIEW = {
  desktop: { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false },
  tablet: { width: 820, height: 1100, deviceScaleFactor: 1, mobile: true },
  mobile: { width: 390, height: 844, deviceScaleFactor: 2, mobile: true },
};
const UA = { desktop: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
  tablet: 'Mozilla/5.0 (Linux; Android 14; SM-X710) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36',
  mobile: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36' };

const PROFILE = mkdtempSync(join(tmpdir(), 'acshots-'));   // removed again when the run ends
const port = 9600 + Math.floor(Math.random() * 300);
const chrome = spawn('google-chrome', ['--headless=new', `--remote-debugging-port=${port}`, '--no-first-run', '--hide-scrollbars',
  `--user-data-dir=${PROFILE}`, `--proxy-server=${process.env.PROXY || 'socks5://127.0.0.1:19080'}`, 'about:blank'], { stdio: 'ignore' });
chrome.on('exit', () => { try { rmSync(PROFILE, { recursive: true, force: true }); } catch {} });   // no profiles left in /tmp
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ws, id = 0; const pending = new Map(), waiters = [];
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const once = method => new Promise(resolve => waiters.push({ method, resolve }));

// runs in the page: invented sample data + markers (plain file – no template-string escaping)
const PAGE_TOOLS = readFileSync(join(HERE, 'page_tools.js'), 'utf8');

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
  });
}
const evaluate = async (expression) => {
  const r = await send('Runtime.evaluate', { expression, returnByValue: true, awaitPromise: true });
  if (r.result?.exceptionDetails) throw new Error('page script failed: ' + (r.result.exceptionDetails.exception?.description || r.result.exceptionDetails.text));
  return r.result?.result?.value;
};

try {
  await connect();
  await send('Page.enable'); await send('Runtime.enable');
  await send('Page.addScriptToEvaluateOnNewDocument', { source: PAGE_TOOLS });
  await send('Network.enable');
  await send('Network.setCookie', { name: 'PHPSESSID', value: process.env.SESSION, url: BASE });
  for (const s of shots) {
    const vp = s.viewport || 'desktop';
    await send('Emulation.setDeviceMetricsOverride', { ...VIEW[vp], ...(s.height ? { height: s.height } : {}) });
    await send('Emulation.setUserAgentOverride', { userAgent: UA[vp] });
    const loaded = once('Page.loadEventFired');
    await send('Page.navigate', { url: BASE + s.path });
    await Promise.race([loaded, sleep(30000)]);
    await sleep(s.wait ?? 3000);
    if (s.setup) { await evaluate(s.setup); await sleep(s.after ?? 1200); }
    // privacy: never capture a page whose personal data could not be replaced
    if (await evaluate('typeof window.__acMask') !== 'function') throw new Error(s.id + ': masking script not loaded – nothing captured');
    await evaluate('window.__acMask()');
    if (await evaluate('typeof window.__acMasked') !== 'number') throw new Error(s.id + ': masking did not finish – nothing captured');
    const leaks = await evaluate('window.__acLeaks()');
    if (leaks && leaks.length && process.env.DEBUG_LEAKS) console.log(await evaluate(`(${JSON.stringify(leaks)}).map(o=>{const w=document.createTreeWalker(document.body,NodeFilter.SHOW_TEXT);let n;while((n=w.nextNode()))if(n.nodeValue.indexOf(o)>-1){const e=n.parentElement;return o.length+' chars in <'+e.tagName.toLowerCase()+' class='+e.className+'> under '+(e.closest('[class]')&&e.closest('[class]').parentElement.className)}return o.length+' chars in a form value'})`));
    if (leaks && leaks.length) throw new Error(s.id + ': personal data still visible after masking (' + leaks.length + ' values) – nothing captured');
    await evaluate('window.__acGeneric()');
    const domain = await evaluate('window.__acDomainLeft()');
    if (domain && domain.length) throw new Error(s.id + ': education wording still visible – nothing captured: ' + domain.join(' | '));
    const missing = await evaluate(`window.__acMark(${JSON.stringify(s.marks || [])})`);
    const shot = await send('Page.captureScreenshot', { format: 'webp', quality: 80 });
    writeFileSync(join(OUT, `${s.id}.webp`), Buffer.from(shot.result.data, 'base64'));
    console.log(`${s.id} [${vp}] ${(s.marks || []).length} marks` + (missing && missing.length ? `  MISSING: ${missing.join(' | ')}` : ''));
  }
} finally {
  try { ws?.close(); } catch {}
  chrome.kill();
}
