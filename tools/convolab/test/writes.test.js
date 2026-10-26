import assert from 'node:assert/strict';
import { test } from 'node:test';
import { mkdtemp, writeFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { savePile } from '../src/piles.js';
import { writeCommand } from '../src/writes.js';
import { newId, requireUlid } from '../src/input.js';

async function input(t, body) {
  const dir = await mkdtemp(join(tmpdir(), 'convolab-write-'));
  t.after(() => rm(dir, { recursive: true }));
  const file = join(dir, 'payload.json');
  await writeFile(file, JSON.stringify(body));
  return { file };
}

test('card creation retains the same identity across caller retries', async t => {
  const { id } = newId();
  const values = await input(t, { id, prompt: { cueText: '犬です。' }, answer: { expression: '犬です。' } });
  const observed = [];
  const api = async (path, options) => { observed.push({ path, ...options }); return { id }; };
  await writeCommand(api, 'create', [], values);
  await writeCommand(api, 'create', [], values);
  assert.deepEqual(observed[0], observed[1]);
  assert.equal(observed[0].body.id, id);
  assert.equal(observed[0].path, '/api/study/cards');
});

test('refuses creation without a persisted identity', async t => {
  const values = await input(t, { prompt: {}, answer: {} });
  await assert.rejects(writeCommand(() => assert.fail('no write'), 'create', [], values), /stable ULID/);
});

test('links explicitly selected mastery milestones using canonical IDs', async t => {
  const predecessor = newId().id;
  const successor = newId().id;
  const values = await input(t, { successor_card_id: successor, unlock_requirement: 'master' });
  await writeCommand(async (path, options) => {
    assert.equal(path, `/api/cards/${predecessor}/learning-path/successor`);
    assert.equal(options.method, 'PUT');
    assert.equal(options.body.unlock_requirement, 'master');
  }, 'link', [predecessor], values);
});

test('prevents blind card edits and implicit unlocking defaults', async t => {
  const values = await input(t, { successor_card_id: newId().id });
  const noWrite = () => assert.fail('no write');
  await assert.rejects(writeCommand(noWrite, 'edit', [newId().id], values), /expectedRevision/);
  await assert.rejects(writeCommand(noWrite, 'link', [newId().id], values), /unlock_requirement/);
});

test('ULID generator supplies canonical distinct IDs and rejects malformed IDs', () => {
  const ids = Array.from({ length: 100 }, () => requireUlid(newId().id));
  assert.equal(new Set(ids).size, 100);
  assert.throws(() => requireUlid('not-an-id'), /stable ULID/);
});


test('pile CLI preserves sparse switches, canonicalizes IDs and rejects nonobjects', async t => {
  const id = newId().id;
  const values = await input(t, { title: 'Genki' });
  const calls = [];
  const api = async (path, options) => { calls.push({ path, options }); return {}; };
  await savePile(api, 'pile-save', [id.toUpperCase()], values);
  await savePile(api, 'chapter-save', [id, id.toUpperCase()], values);
  assert.equal(calls[0].path, `/api/study/piles/${id}`);
  assert.equal(calls[1].path, `/api/study/piles/${id}/chapters/${id}`);
  assert.deepEqual(calls[1].options.body, { title: 'Genki' });
  await assert.rejects(savePile(api, 'pile-save', ['invalid'], values), /stable ULID/);
  await assert.rejects(savePile(api, 'pile-save', [id], await input(t, [])), /JSON object/);
});

test('batch CLI routes source assignment and Learn soon without dropping flags', async t => {
  for (const [command, body] of [['card-sources', { cardIds: [newId().id], bookSource: null }], ['learn-soon', { cardIds: [newId().id], learnSoon: false }]]) {
    await writeCommand(async (path, options) => {
      assert.equal(path, `/api/study/${command}`);
      assert.equal(options.method, 'PUT');
      assert.deepEqual(options.body, body);
    }, command, [], await input(t, body));
  }
});
