// End-to-end run, all local and all fake: a throwaway SQLite API (PHP's built-in server), the built Astro site,
// then Playwright (installed Chrome, no browser download). Run from web/: npm run build && npm run e2e
// Nothing here talks to Cashflows, Resend, drand or any staging/production host: the API gets unreachable
// hosts for those, the log mailer and the fake payment driver, and tests/E2E/seed.php refuses to run otherwise.
// Only the processes this script starts are stopped, by their own handles.
import { spawn, spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, rmSync, writeFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import path from 'node:path';

const web = fileURLToPath(new URL('..', import.meta.url));
const root = path.resolve(web, '..');
const dir = path.join(root, 'storage', 'e2e');
rmSync(dir, { recursive: true, force: true });
mkdirSync(dir, { recursive: true });
const db = path.join(dir, 'e2e.sqlite');
writeFileSync(db, '');

const API_PORT = 8765;
const WEB_PORT = 4765;
const key = () => 'base64:' + randomBytes(32).toString('base64');
const password = 'e2e-' + randomBytes(9).toString('hex'); // made up per run, never a real password
const offline = 'http://127.0.0.1:9';

const apiEnv = {
  ...process.env,
  APP_ENV: 'local', APP_DEBUG: 'false', APP_KEY: key(), APP_URL: `http://127.0.0.1:${API_PORT}`,
  FRONTEND_URL: `http://127.0.0.1:${WEB_PORT}`,
  DB_CONNECTION: 'sqlite', DB_DATABASE: db, DB_URL: '',
  MAIL_MAILER: 'log', RESEND_API_KEY: '', LOG_CHANNEL: 'single',
  QUEUE_CONNECTION: 'sync', CACHE_STORE: 'file', SESSION_DRIVER: 'file',
  PAYMENT_DRIVER: 'fake', CASHFLOWS_BASE_URL: offline, CASHFLOWS_API_KEY: '', CASHFLOWS_CONFIGURATION_ID: '', CASHFLOWS_WEBHOOK_TOKEN: '',
  VERITY_BEACON: 'local', VERITY_BEACON_FALLBACK: 'false', VERITY_DRAND_URL: offline, VERITY_DRAND_RELAYS: offline,
  VERITY_TSA_URL: '', VERITY_ALERT_EMAILS: '', VERITY_ANCHOR_EMAILS: '',
  VERITY_SEED_KEY: key(), VERITY_OWNER_PEPPER: randomBytes(16).toString('hex'),
  E2E_PASSWORD: password,
};

const php = process.env.PHP_BINARY || 'php';
const seed = spawnSync(php, [path.join(root, 'tests', 'E2E', 'seed.php')], { cwd: root, env: apiEnv, stdio: 'inherit' });
if (seed.error) console.error(`Could not run ${php} (set PHP_BINARY):`, seed.error.message);
if (seed.status !== 0) process.exit(seed.status ?? 1);

const started = [];
const start = (cmd, args, opts) => {
  const child = spawn(cmd, args, { ...opts, stdio: ['ignore', 'ignore', 'inherit'] });
  started.push(child);
  // So a debugging session (E2E_SERVE_ONLY) can be stopped by these exact PIDs.
  writeFileSync(path.join(dir, 'pids.txt'), [process.pid, ...started.map((c) => c.pid)].join(' '));
  return child;
};
const stopAll = () => { for (const c of started) if (c.exitCode === null) c.kill(); };
process.on('exit', stopAll);
process.on('SIGINT', () => { stopAll(); process.exit(130); });

start(php, ['-S', `127.0.0.1:${API_PORT}`, '-t', path.join(root, 'public'), path.join(root, 'vendor', 'laravel', 'framework', 'src', 'Illuminate', 'Foundation', 'resources', 'server.php')], { cwd: path.join(root, 'public'), env: apiEnv });
start(process.execPath, [path.join(web, 'dist', 'server', 'entry.mjs')], {
  cwd: web, env: { ...process.env, API_URL: `http://127.0.0.1:${API_PORT}`, HOST: '127.0.0.1', PORT: String(WEB_PORT) },
});

const wait = async (url) => {
  for (let i = 0; i < 150; i++) {
    try { const r = await fetch(url); if (r.status < 500) return; } catch { /* not up yet */ }
    await new Promise((r) => setTimeout(r, 200));
  }
  throw new Error(`${url} did not start`);
};
await wait(`http://127.0.0.1:${API_PORT}/api/settings`);
await wait(`http://127.0.0.1:${WEB_PORT}/robots.txt`);
if (process.env.E2E_SERVE_ONLY) {
  // Debugging: keep the servers up (stop this script to stop them). The made-up password is in storage/e2e.
  writeFileSync(path.join(dir, 'password.txt'), password);
  console.log(`API http://127.0.0.1:${API_PORT}  site http://127.0.0.1:${WEB_PORT}`);
  await new Promise(() => {});
}

const pw = spawnSync(process.execPath, [path.join(web, 'node_modules', '@playwright', 'test', 'cli.js'), 'test', '--config', path.join(web, 'e2e', 'playwright.config.mjs'), ...process.argv.slice(2)], {
  cwd: web, stdio: 'inherit',
  env: { ...process.env, E2E_BASE: `http://127.0.0.1:${WEB_PORT}`, E2E_API: `http://127.0.0.1:${API_PORT}`, E2E_PASSWORD: password, E2E_ROOT: root },
});
stopAll();
process.exit(pw.status ?? 1);
