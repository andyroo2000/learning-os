import { execFile, spawn } from 'node:child_process';
import { promisify } from 'node:util';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { createServer } from 'node:net';
import { once } from 'node:events';
import { setTimeout as delay } from 'node:timers/promises';
import { randomBytes } from 'node:crypto';
import { scenario } from './scenario.js';

const root = new URL('../../../', import.meta.url).pathname;
const execute = promisify(execFile);

async function availablePort() {
  const server = createServer().listen(0, '127.0.0.1');
  await once(server, 'listening');
  const port = server.address().port;
  await new Promise(resolve => server.close(resolve));
  return port;
}

function hasExited(server) {
  return server.exitCode !== null || server.signalCode !== null;
}

async function waitForServer(origin, server) {
  for (let attempt = 0; attempt < 50; attempt += 1) {
    if (hasExited(server) || server.startupError) {
      throw new Error(`Local Laravel server exited during startup: ${server.startupError ?? server.diagnostics}`);
    }
    try { if ((await fetch(`${origin}/up`)).ok) return; } catch { /* Server is starting. */ }
    await delay(100);
  }
  throw new Error(`Local Laravel server did not become healthy: ${server.diagnostics}`);
}

function isolatedEnvironment(directory) {
  return { ...process.env, APP_ENV: 'testing', APP_KEY: `base64:${randomBytes(32).toString('base64')}`,
    APP_CONFIG_CACHE: join(directory, 'config.php'), DB_CONNECTION: 'sqlite', DB_DATABASE: join(directory, 'database.sqlite'), DB_URL: '',
    CACHE_STORE: 'array', SESSION_DRIVER: 'array', QUEUE_CONNECTION: 'database', MAIL_MAILER: 'array', BCRYPT_ROUNDS: '4',
  };
}

function startServer(port, env) {
  const router = join(root, 'vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php');
  const server = spawn('php', ['-d', 'display_errors=0', '-S', `127.0.0.1:${port}`, '-t', '.', router], {
    cwd: join(root, 'public'), env, stdio: ['ignore', 'ignore', 'pipe'],
  });
  server.diagnostics = '';
  server.stderr.on('data', chunk => { server.diagnostics = (server.diagnostics + chunk).slice(-4000); });
  server.on('error', error => { server.startupError = error.message; });
  return server;
}

async function stopServer(server) {
  if (!server?.pid || hasExited(server)) return;
  const exited = once(server, 'exit');
  server.kill();
  await exited;
}

async function main() {
  const directory = await mkdtemp(join(tmpdir(), 'convolab-integration-'));
  const env = isolatedEnvironment(directory);
  let server;
  try {
    await writeFile(env.DB_DATABASE, '');
    await execute('php', ['artisan', 'migrate', '--force'], { cwd: root, env });
    const seeded = await execute('php', ['tools/convolab/integration/seed.php'], { cwd: root, env });
    const tokens = JSON.parse(seeded.stdout);
    const port = await availablePort();
    const baseUrl = `http://127.0.0.1:${port}`;
    server = startServer(port, env);
    await waitForServer(baseUrl, server);
    const profile = join(directory, 'profile.json');
    const other = join(directory, 'other.json');
    await writeFile(profile, JSON.stringify({ baseUrl, token: tokens.token }), { mode: 0o600 });
    await writeFile(other, JSON.stringify({ baseUrl, token: tokens.otherToken }), { mode: 0o600 });
    await scenario(profile, directory, other);
    console.log(JSON.stringify({ ok: true, database: 'isolated SQLite', cleaned: true }));
  } finally {
    await stopServer(server);
    await rm(directory, { recursive: true });
  }
}

await main();
