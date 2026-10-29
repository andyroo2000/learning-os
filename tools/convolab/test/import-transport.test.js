import test from 'node:test';
import assert from 'node:assert/strict';
import { createServer } from 'node:http';
import { once } from 'node:events';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { join } from 'node:path';
import { tmpdir } from 'node:os';
import { createClient } from '../src/client.js';
import { inspectArchive, uploadArchive } from '../src/import-file.js';

test('binary upload streams original bytes with origin-bound authentication and an exact content length', async t => {
  const directory = await mkdtemp(join(tmpdir(), 'import-transport-'));
  const archivePath = join(directory, 'original.colpkg');
  const bytes = Buffer.from([0, 1, 2, 255, 34, 92]);
  await writeFile(archivePath, bytes);
  const id = '01jzq4nny5xbnzw14q1g68b2yt';
  const server = createServer(async (req, res) => {
    assert.equal(req.url, `/api/study/imports/${id}/upload`);
    assert.equal(req.headers.authorization, 'Bearer fixture-secret');
    assert.equal(req.headers['content-type'], 'application/octet-stream');
    assert.equal(Number(req.headers['content-length']), bytes.length);
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    assert.deepEqual(Buffer.concat(chunks), bytes);
    res.end(JSON.stringify({ data: { uploaded: true } }));
  }).listen(0, '127.0.0.1');
  await once(server, 'listening');
  t.after(async () => { server.closeAllConnections(); await new Promise(resolve => server.close(resolve)); await rm(directory, { recursive: true }); });
  const api = createClient({ baseUrl: `http://127.0.0.1:${server.address().port}`, token: 'fixture-secret' });
  const result = await uploadArchive(api, { id, archivePath, ...await inspectArchive(archivePath) });
  assert.equal(result.data.uploaded, true);
  await assert.rejects(api('/api/me', { method: 'PUT', upload: { body: bytes, size: bytes.length } }), /study import job/);
});
