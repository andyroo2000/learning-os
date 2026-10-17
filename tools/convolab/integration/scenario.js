import assert from 'node:assert/strict';
import { writeFile } from 'node:fs/promises';
import { join } from 'node:path';
import { setTimeout as delay } from 'node:timers/promises';
import { createClient, loadProfile } from '../src/client.js';
import { newId } from '../src/input.js';
import { verifyDraft } from './drafts.js';
import { harness, cleanup, failsWith } from './harness.js';

function cardPayload(id, label, cloze = false) {
  const expression = '犬です。';
  return {
    id, creationKind: cloze ? 'cloze' : 'text-recognition',
    prompt: cloze ? { clozeText: '[犬]です。' } : { cueText: expression, cueReading: '犬[いぬ]です。' },
    answer: { expression, expressionReading: '犬[いぬ]です。', meaning: "It's a dog.", notes: label },
  };
}

async function verifyEditing(run, id, original) {
  const payload = { prompt: original.prompt, answer: { ...original.answer, meaning: 'It is a dog.' }, expectedRevision: original.revision };
  const edited = await run('edit', [id], payload);
  assert.equal(edited.answer.meaning, 'It is a dog.');
  await failsWith(run('edit', [id], { ...payload, answer: { ...payload.answer, meaning: 'Stale edit.' } }), 409);
}

async function verifyPath(run, ids) {
  const link = { successor_card_id: ids[1], unlock_requirement: 'successful_retrieval' };
  await run('link', [ids[0]], link);
  await run('link', [ids[0]], link);
  await run('link', [ids[1]], { successor_card_id: ids[2], unlock_requirement: 'master' });
  const path = (await run('path', [ids[0]])).data;
  assert.equal(path.stages.length, 3);
  assert.equal(path.stages[1].cards[0].variant_status, 'locked');
  assert.equal(path.stages[2].cards[0].variant_unlock_requirement, 'master');
}

async function verifyUnlock(run, profile, ids) {
  const api = createClient(await loadProfile(profile));
  for (let index = 0; index < 2; index += 1) {
    if (index > 0) await delay(1000);
    await api('/api/card-review-events', { method: 'POST', body: {
      id: newId().id, card_id: ids[0], rating: 'good', reviewed_at: new Date().toISOString(),
    } });
  }
  const reviews = await run('reviews', [], undefined);
  assert.ok(reviews.data.length >= 2);
  const path = (await run('path', [ids[0]])).data;
  assert.equal(path.stages[1].cards[0].variant_status, 'available');
  assert.equal(path.stages[2].cards[0].variant_status, 'locked');
}

export async function scenario(profile, directory, otherProfile, media = false) {
  const run = harness(profile, directory);
  const ids = Array.from({ length: 3 }, () => newId().id);
  const draftId = newId().id;
  const draftCardId = newId().id;
  const label = `CLI integration ${ids[0]}`;
  const manifestPath = join(directory, 'manifest.json');
  const account = await run('whoami');
  await writeFile(manifestPath, JSON.stringify({ cards: [...ids, draftCardId], drafts: [draftId], label, accountId: account.data.id }), { mode: 0o600 });
  try {
    const first = media ? await verifyDraft(run, { draftId, cardId: ids[0], label, media }) : undefined;
    await exercise(run, { profile, ids, label, first });
    if (!media) await verifyDraft(run, { draftId, cardId: draftCardId, label, media });
    if (otherProfile) await failsWith(harness(otherProfile, directory)('card', [ids[0]]), 404);
  } catch (error) {
    process.stderr.write(`Lifecycle failed: ${error.message}\n`);
    throw error;
  } finally {
    await cleanup(run, manifestPath);
  }
  return { ok: true, createdAndDeleted: ids, deletedDrafts: [draftId], manifestPath };
}

async function exercise(run, { profile, ids, label, first }) {
  if (!first) first = await createWithRetry(run, ids[0], label);
  assert.equal(first.syncId, ids[0]);
  await verifyEditing(run, ids[0], first);
  await run('create', [], cardPayload(ids[1], label));
  await run('create', [], cardPayload(ids[2], label, true));
  const search = await run('search', ['--q', label, '--limit', '1']);
  assert.equal(search.total, 3);
  assert.ok(search.nextCursor);
  const next = await run('search', ['--q', label, '--limit', '1', '--cursor', search.nextCursor]);
  assert.notDeepEqual(search.rows, next.rows);
  await verifyPath(run, ids);
  await verifyUnlock(run, profile, ids);
}

async function createWithRetry(run, id, label) {
  const first = await run('create', [], cardPayload(id, label));
  assert.equal((await run('create', [], cardPayload(id, label))).syncId, id);
  return first;
}
