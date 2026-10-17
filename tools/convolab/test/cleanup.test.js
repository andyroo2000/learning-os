import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtemp, writeFile, readFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { cleanup } from '../integration/harness.js';

async function manifest(t) {
  const directory = await mkdtemp(join(tmpdir(), 'cli-cleanup-'));
  t.after(() => rm(directory, { recursive: true }));
  const file = join(directory, 'manifest.json');
  await writeFile(file, JSON.stringify({ accountId: 7, cards: ['created-card'], drafts: ['created-draft'] }));
  return file;
}

function missing() {
  return Object.assign(new Error('missing'), { stderr: JSON.stringify({ status: 404 }) });
}

test('cleanup refuses a different account before any deletion', async t => {
  const file = await manifest(t);
  await assert.rejects(cleanup(async command => {
    assert.equal(command, 'whoami');
    return { data: { id: 8 } };
  }, file), /must match/);
  assert.equal(JSON.parse(await readFile(file)).cleaned, undefined);
});

test('cleanup continues after failure and retains a recoverable manifest', async t => {
  const file = await manifest(t);
  const deleted = [];
  await assert.rejects(cleanup(async (command, args) => {
    if (command === 'whoami') return { data: { id: 7 } };
    if (command === 'draft-delete') throw new Error('network unavailable');
    if (command === 'delete') { deleted.push(args[0]); return { ok: true }; }
    throw missing();
  }, file), /created-draft/);
  assert.deepEqual(deleted, ['created-card', 'created-card']);
  assert.equal(JSON.parse(await readFile(file)).cleaned, undefined);
});

test('cleanup confirms missing resources and marks only the recorded run complete', async t => {
  const file = await manifest(t);
  const observed = new Set();
  await cleanup(async (command, args) => {
    if (command === 'whoami') return { data: { id: 7 } };
    observed.add(args[0]);
    throw missing();
  }, file);
  assert.deepEqual([...observed].sort(), ['created-card', 'created-draft']);
  assert.equal(JSON.parse(await readFile(file)).cleaned, true);
});
