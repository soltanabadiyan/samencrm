// Address of the CRM for the browser tools: BASE from the environment, else built from server.env at the repo
// root (SERVER_HOST, or the host part of SSH_TARGET, and WEB_PORT). server.env is not in git – see server.env.example.
import { readFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

export function serverEnv(file = join(dirname(fileURLToPath(import.meta.url)), '..', 'server.env')) {
  const env = {};
  if (existsSync(file)) {
    for (const line of readFileSync(file, 'utf8').split('\n')) {
      const m = /^([A-Z_][A-Z0-9_]*)=(.*)$/.exec(line.trim());
      if (m) env[m[1]] = m[2];
    }
  }
  return env;
}

export function baseUrl(env = process.env, file) {
  if (env.BASE) return env.BASE;
  const f = serverEnv(file);
  const host = env.SERVER_HOST || f.SERVER_HOST || (env.SSH_TARGET || f.SSH_TARGET || '').replace(/^.*@/, '');
  if (!host) {
    console.error('No server address: set BASE=http://host:port/ or copy server.env.example to server.env');
    process.exit(1);
  }
  return `http://${host}:${env.WEB_PORT || f.WEB_PORT || 9081}/`;
}
