import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { importTools } from '../src/mcp/import-tools.js';
import { inspectArchive } from '../src/import-file.js';

const id = '01jzq4nny5xbnzw14q1g68b2yt';
const resume = importTools.find(t => t.name === 'resume_import').execute;
async function archive(t) {
  const dir = await mkdtemp(join(tmpdir(), 'mcp-import-'));
  t.after(() => rm(dir, { recursive: true }));
  const archivePath = join(dir, 'original.colpkg');
  await writeFile(archivePath, Buffer.from('original recording bytes'));
  return { id, archivePath, ...await inspectArchive(archivePath) };
}

test('interrupted uploads resume with the same job and exact bytes, without creating another import', async t => {
  const args = await archive(t);
  const calls = [];
  let interrupted = true;
  const api = async (path, options) => {
    calls.push(path);
    if (!options) return { data: { status: 'pending', source_filename: args.filename, upload_completed_at: null } };
    if (options.upload) {
      const chunks = [];
      for await (const chunk of options.upload.body) chunks.push(chunk);
      assert.equal(Buffer.concat(chunks).toString(), 'original recording bytes');
      if (interrupted) { interrupted = false; throw new Error('Connection lost.'); }
    }
    return { data: { status: 'pending' } };
  };
  await assert.rejects(resume(api, args), /Connection lost/);
  assert.ok(!calls.some(p => p.endsWith('/complete')));
  await resume(api, args);
  assert.deepEqual(calls, [`/api/study/imports/${id}`, `/api/study/imports/${id}/upload`, `/api/study/imports/${id}`, `/api/study/imports/${id}/upload`, `/api/study/imports/${id}/complete`]);
});

test('queued or terminal jobs do not upload again and pending completion is recoverable', async t => {
  const args = await archive(t);
  for (const status of ['processing', 'completed', 'failed', 'pending']) {
    const writes = [];
    await resume(async (path, options) => {
      if (options) writes.push(path);
      return { data: { status, upload_completed_at: '2026-10-29T00:00:00Z' } };
    }, args);
    assert.deepEqual(writes, status === 'pending' ? [`/api/study/imports/${id}/complete`] : []);
  }
});

test('changed archives or filenames are rejected before uploading', async t => {
  const args = await archive(t);
  const api = async (_, options) => {
    assert.equal(options, undefined);
    return { data: { status: 'pending', source_filename: args.filename } };
  };
  await assert.rejects(resume(api, { ...args, sha256: '0'.repeat(64) }), /checksum/);
  await assert.rejects(resume(async () => ({ data: { status: 'pending', source_filename: 'other.colpkg' } }), args), /filename/);
});

test('inspection rejects nonarchives and empty files', async t => {
  const args = await archive(t);
  await assert.rejects(inspectArchive('relative.colpkg'), /absolute/);
  await writeFile(args.archivePath, '');
  await assert.rejects(inspectArchive(args.archivePath), /nonempty/);
});
