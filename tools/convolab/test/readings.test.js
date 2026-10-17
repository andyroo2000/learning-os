import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { importReading } from '../src/readings.js';
import { newId } from '../src/input.js';
import { createClient } from '../src/client.js';
import { createServer } from 'node:http';

test('import resolves local images beside manifest and never sends their paths', async t => {
  const directory = await mkdtemp(join(tmpdir(), 'reading-import-'));
  t.after(() => rm(directory, { recursive: true }));
  await writeFile(join(directory, 'page.png'), Buffer.from([1, 2, 3]));
  const file = join(directory, 'reading.json');
  const id = newId().id;
  await writeFile(file, JSON.stringify({ id, document: { pages: [{ number: 24, imageFile: 'page.png' }] } }));
  await importReading(async (path, options) => {
    assert.equal(path, '/api/reading-imports');
    assert.equal(options.multipart.get('id'), id);
    assert.equal(options.multipart.get('images[24]').size, 3);
    assert.deepEqual(JSON.parse(options.multipart.get('document')), { pages: [{ number: 24 }] });
  }, [], { file });
});

test('multipart transport retains origin-bound authentication and generated boundary', async t => {
  let observed;
  const server = createServer(async (req, res) => {
    const chunks = [];
    for await (const chunk of req) chunks.push(chunk);
    observed = { auth: req.headers.authorization, type: req.headers['content-type'], body: Buffer.concat(chunks).toString() };
    res.setHeader('Content-Type', 'application/json');
    res.end('{"ok":true}');
  });
  await new Promise(resolve => server.listen(0, '127.0.0.1', resolve));
  t.after(() => server.close());
  const api = createClient({ baseUrl: `http://127.0.0.1:${server.address().port}`, token: 'fixture' });
  const form = new FormData();
  form.append('document', '{"title":"Book"}');
  await api('/api/reading-imports', { method: 'POST', multipart: form });
  assert.equal(observed.auth, 'Bearer fixture');
  assert.match(observed.type, /^multipart\/form-data; boundary=/);
  assert.match(observed.body, /name="document"/);
});

test('import requires a saved manifest rather than ambiguous stdin image paths', async () => {
  await assert.rejects(importReading(() => assert.fail('no request'), [], { file: '-' }), /local manifest/);
});
