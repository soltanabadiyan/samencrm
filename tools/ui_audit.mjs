#!/usr/bin/env node
// Visual audit of the CRM: runs a list of steps (page + optional interaction) on desktop and mobile viewports
// and saves a screenshot per step. Chrome DevTools Protocol over Node's built-in WebSocket (no npm deps).
//   SESSION=$(tools/get_session.sh) node tools/ui_audit.mjs out_dir tools/ui_audit_steps.json [filter]
// Needs a SOCKS tunnel to the server:  ssh -D 19080 hashemz_crm  (through tools/direct_proxy.py when on VPN).
// GAMA (/opt/gama, 9071) from outside the router: ssh -L 19071:127.0.0.1:9071 hashemz_crm, then
//   (CHROME_ARGS: extra Chrome flags separated by ||)  BASE=http://212.33.206.241:9071/ PROXY=direct:// CHROME_ARGS="--host-resolver-rules=MAP 212.33.206.241:9071 127.0.0.1:19071"
import { spawn } from 'node:child_process';
import { rmSync, mkdirSync, writeFileSync, mkdtempSync, readFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [out, stepsFile, filter] = process.argv.slice(2);
const BASE = process.env.BASE || 'http://212.33.206.241:9081/';
const steps = JSON.parse(readFileSync(stepsFile, 'utf8')).filter(s => !filter || s.name.includes(filter));
mkdirSync(out, { recursive: true });
const VIEW = {
  desktop: { width: 1440, height: 900, deviceScaleFactor: 1, mobile: false },
  mobile: { width: 390, height: 844, deviceScaleFactor: 1, mobile: true },
};
const MOBILE_UA = 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Mobile Safari/537.36';
const PROFILE = mkdtempSync(join(tmpdir(), 'uiaudit-'));   // removed again when the run ends
const port = 9300 + Math.floor(Math.random() * 500);
const chrome = spawn('google-chrome', ['--headless=new', `--remote-debugging-port=${port}`, '--no-first-run',
  `--user-data-dir=${PROFILE}`, `--proxy-server=${process.env.PROXY || 'socks5://127.0.0.1:19080'}`,
  ...(process.env.CHROME_ARGS ? process.env.CHROME_ARGS.split('||') : []), 'about:blank'], { stdio: 'ignore' });
chrome.on('exit', () => { try { rmSync(PROFILE, { recursive: true, force: true }); } catch {} });   // no profiles left in /tmp
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ws, id = 0; const pending = new Map(), waiters = [];
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const once = method => new Promise(resolve => waiters.push({ method, resolve }));
const consoleErrors = [];

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
    if (m.method === 'Runtime.exceptionThrown') consoleErrors.push(m.params.exceptionDetails?.exception?.description?.split('\n')[0]);
    if (m.method) waiters.filter(w => w.method === m.method).forEach(w => w.resolve(m));
  });
}

try {
  await connect();
  await send('Page.enable'); await send('Network.enable'); await send('Runtime.enable');
  if (process.env.SESSION) await send('Network.setCookie', { name: 'PHPSESSID', value: process.env.SESSION, url: BASE });
  let current = '';
  for (const s of steps) {
    for (const vp of (s.viewports || ['desktop'])) {
      const v = VIEW[vp];
      await send('Emulation.setDeviceMetricsOverride', v);
      await send('Emulation.setUserAgentOverride', { userAgent: vp === 'mobile' ? MOBILE_UA : 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0 Safari/537.36' });
      if (s.cookieless) await send('Network.clearBrowserCookies');
      await send('Emulation.setEmulatedMedia', { media: s.media || '' });   // "print" = see the page as printed
      const key = vp + '|' + s.path;
      if (s.reload !== false || key !== current) {
        const loaded = once('Page.loadEventFired');
        await send('Page.navigate', { url: BASE + s.path });
        await Promise.race([loaded, sleep(30000)]);
        await sleep(s.wait ?? 3000);
        current = key;
      }
      if (s.eval) {
        const r = await send('Runtime.evaluate', { expression: s.eval, returnByValue: true, awaitPromise: true });
        const val = r.result?.result?.value;
        if (val !== undefined) console.log(`${s.name} [${vp}] =>`, JSON.stringify(val).slice(0, +(process.env.MAXOUT || 600)));
        await sleep(s.after ?? 900);
      }
      const shot = await send('Page.captureScreenshot', { format: 'png' });
      const file = join(out, `${s.name}.${vp}.png`);
      writeFileSync(file, Buffer.from(shot.result.data, 'base64'));
      console.log('saved', file);
      if (s.cookieless && process.env.SESSION) await send('Network.setCookie', { name: 'PHPSESSID', value: process.env.SESSION, url: BASE });
    }
  }
  if (consoleErrors.length) console.log('JS errors:', [...new Set(consoleErrors)].slice(0, 10));
} finally {
  try { ws?.close(); } catch {}
  chrome.kill();
}
