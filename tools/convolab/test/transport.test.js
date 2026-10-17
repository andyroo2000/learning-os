import assert from 'node:assert/strict';
import { test } from 'node:test';
import { createServer } from 'node:http';
import { once } from 'node:events';
import { mkdtemp, writeFile, rm, chmod } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { promisify } from 'node:util';
import { execFile } from 'node:child_process';

const execute = promisify(execFile);
const cli = new URL('../cli.js', import.meta.url).pathname;

async function fixture(t, handler) {
  const dir = await mkdtemp(join(tmpdir(), 'convolab-cli-'));
  const server = createServer(handler).listen(0, '127.0.0.1');
  await once(server, 'listening');
  t.after(async () => { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); await rm(dir, { recursive: true }); });
  const profile = join(dir, 'profile.json');
  await writeFile(profile, JSON.stringify({ baseUrl: `http://127.0.0.1:${server.address().port}`, token: 'fixture-secret' }), { mode: 0o600 });
  return profile;
}

test('real executable sends bearer credentials and emits API JSON', async t => {
  const profile = await fixture(t, (request, response) => {
    assert.equal(request.headers.authorization, 'Bearer fixture-secret');
    assert.equal(new URL(request.url, 'http://local').searchParams.get('q'), 'でも');
    response.setHeader('Content-Type', 'application/json');
    response.end(JSON.stringify({ rows: [], nextCursor: 'remaining' }));
  });
  const { stdout, stderr } = await execute(process.execPath, [cli, 'search', '--q', 'でも', '--profile', profile]);
  assert.deepEqual(JSON.parse(stdout), { rows: [], nextCursor: 'remaining' });
  assert.equal(stderr, '');
});

test('HTTP errors produce a nonzero exit and do not print response secrets', async t => {
  const profile = await fixture(t, (_, response) => {
    response.writeHead(429, { 'Retry-After': '60' });
    response.end('fixture-secret');
  });
  await assert.rejects(execute(process.execPath, [cli, 'whoami', '--profile', profile]), error => {
    const result = JSON.parse(error.stderr);
    assert.equal(result.status, 429);
    assert.equal(result.retryAfter, '60');
    assert.equal(error.stdout, '');
    assert.ok(!error.stderr.includes('fixture-secret'));
    return true;
  });
});

test('refuses credential files readable by other users', async t => {
  const profile = await fixture(t, () => assert.fail('no request expected'));
  await chmod(profile, 0o644);
  await assert.rejects(execute(process.execPath, [cli, 'whoami', '--profile', profile]), /mode 600/);
});

test('refuses redirects instead of forwarding credentials', async t => {
  const profile = await fixture(t, (_, response) => {
    response.writeHead(302, { Location: 'https://example.com' });
    response.end();
  });
  await assert.rejects(execute(process.execPath, [cli, 'whoami', '--profile', profile]), /fetch failed/);
});


test('malformed profile and API JSON never echo token fragments', async t => {
  const profile = await fixture(t, (_, response) => response.end('fixture-secret invalid json'));
  const check = error => {
    assert.ok(!error.stderr.includes('fixture-secret'));
    assert.match(error.stderr, /contents withheld/);
    return true;
  };
  await assert.rejects(execute(process.execPath, [cli, 'whoami', '--profile', profile]), check);
  await writeFile(profile, '{"token": fixture-secret}');
  await assert.rejects(execute(process.execPath, [cli, 'whoami', '--profile', profile]), check);
});
