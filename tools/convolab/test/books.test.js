import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { run } from '../src/run.js';
import { newId } from '../src/input.js';
import { createServer } from 'node:http';

test('book commands send authenticated stable creation, ordering, and page requests', async t => {
  const requests = [];
  const server = createServer(async (req, res) => {
    let body = '';
    for await (const chunk of req) body += chunk;
    requests.push({ path: req.url, method: req.method, auth: req.headers.authorization, body: body ? JSON.parse(body) : null });
    res.setHeader('Content-Type', 'application/json');
    res.end(JSON.stringify({ ok: true }));
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  t.after(() => server.close());
  const dir = await mkdtemp(join(tmpdir(), 'convolab-books-'));
  t.after(() => rm(dir, { recursive: true }));
  const profile = join(dir, 'profile.json');
  await writeFile(profile, JSON.stringify({ baseUrl: `http://127.0.0.1:${server.address().port}`, token: 'test' }), { mode: 0o600 });
  const id = newId().id;
  const payload = join(dir, 'book.json');
  await writeFile(payload, JSON.stringify({ id, title: 'Forest Tales' }));
  await run(['book-create', '--file', payload, '--profile', profile]);
  await run(['book-create', '--file', payload, '--profile', profile]);
  assert.deepEqual(requests[0], requests[1]);
  assert.equal(requests[0].body.id, id);
  assert.equal(requests[0].auth, 'Bearer test');
  await writeFile(payload, JSON.stringify({ expectedRevision: 1, readingIds: [] }));
  await run(['book-order', id, '--file', payload, '--profile', profile]);
  assert.equal(requests[2].method, 'PUT');
  assert.equal(requests[2].path, `/api/reading-books/${id}/contents`);
  await run(['books', '--page', '2', '--profile', profile]);
  assert.equal(requests[3].path, '/api/reading-books?page=2');
});
