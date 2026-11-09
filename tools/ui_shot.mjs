#!/usr/bin/env node
// Headless screenshots of the CRM for UI checks (RTL/Jalali). No npm deps: Chrome DevTools Protocol over
// Node's built-in WebSocket.  Usage:
//   SESSION=<PHPSESSID> node tools/ui_shot.mjs out_dir "index.php?module=Contacts&view=List" ...
// Env: BASE (default http://212.33.206.241:9081/), PROXY (default socks5://127.0.0.1:19080), W/H viewport,
//      EVAL (optional JS to run after load, e.g. open a menu; result printed), POSTWAIT ms before the shot.
import { spawn } from 'node:child_process';
import { mkdirSync, writeFileSync, mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const [out, ...paths] = process.argv.slice(2);
const BASE = process.env.BASE || 'http://212.33.206.241:9081/';
const W = +(process.env.W || 1440), H = +(process.env.H || 900);
mkdirSync(out, { recursive: true });
const port = 9300 + Math.floor(Math.random() * 500);
const chrome = spawn('google-chrome', ['--headless=new', `--remote-debugging-port=${port}`, '--no-first-run',
  `--user-data-dir=${mkdtempSync(join(tmpdir(), 'uishot-'))}`, `--proxy-server=${process.env.PROXY || 'socks5://127.0.0.1:19080'}`,
  `--window-size=${W},${H}`, 'about:blank'], { stdio: 'ignore' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
let ws, id = 0; const pending = new Map(), waiters = [];
async function connect() {
  for (let i = 0; i < 50; i++) {
    try {
      const list = await (await fetch(`http://127.0.0.1:${port}/json/list`)).json();
      const page = list.find(t => t.type === 'page');
      if (page) { ws = new WebSocket(page.webSocketDebuggerUrl); break; }
    } catch { /* not up yet */ }
    await sleep(200);
  }
  await new Promise(r => ws.addEventListener('open', r));
  ws.addEventListener('message', e => {
    const m = JSON.parse(e.data);
    if (m.id && pending.has(m.id)) { pending.get(m.id)(m); pending.delete(m.id); }
    if (m.method) waiters.filter(w => w.method === m.method).forEach(w => w.resolve(m));
  });
}
const send = (method, params = {}) => new Promise(r => { const i = ++id; pending.set(i, r); ws.send(JSON.stringify({ id: i, method, params })); });
const once = method => new Promise(resolve => waiters.push({ method, resolve }));

try {
  await connect();
  await send('Page.enable'); await send('Network.enable'); await send('Runtime.enable');
  await send('Emulation.setDeviceMetricsOverride', { width: W, height: H, deviceScaleFactor: 1, mobile: false });
  if (process.env.SESSION) await send('Network.setCookie', { name: 'PHPSESSID', value: process.env.SESSION, url: BASE });
  for (const p of paths) {
    const loaded = once('Page.loadEventFired');
    await send('Page.navigate', { url: BASE + p });
    await Promise.race([loaded, sleep(30000)]);
    await sleep(+(process.env.WAIT || 3500));
    if (process.env.EVAL) {
      const r = await send('Runtime.evaluate', { expression: process.env.EVAL, returnByValue: true, awaitPromise: true });
      console.log(p, '=>', JSON.stringify(r.result?.result?.value ?? r.result));
      await sleep(+(process.env.POSTWAIT || 800));
    }
    const shot = await send('Page.captureScreenshot', { format: 'png' });
    const name = p.replace(/[^a-z0-9]+/gi, '_').slice(0, 80) + '.png';
    writeFileSync(join(out, name), Buffer.from(shot.result.data, 'base64'));
    console.log('saved', join(out, name));
  }
} finally {
  try { ws?.close(); } catch {}
  chrome.kill();
}
