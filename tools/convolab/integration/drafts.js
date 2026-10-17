import assert from 'node:assert/strict';
import { setTimeout as delay } from 'node:timers/promises';
import { failsWith } from './harness.js';

async function readyDraft(run, id) {
  const deadline = Date.now() + 180_000;
  while (Date.now() < deadline) {
    const draft = await run('draft', [id]);
    if (draft.status === 'ready') return draft;
    if (draft.status === 'failed') throw new Error(`Draft generation failed: ${id}`);
    await delay(3000);
  }
  throw new Error(`Draft generation timed out; inspect ${id} before retrying.`);
}

export async function verifyDraft(run, { draftId, cardId, label, media }) {
  const payload = {
    id: draftId, creationKind: media ? 'audio-recognition' : 'text-recognition', cardType: 'recognition',
    prompt: media ? {} : { cueText: '犬です。', cueReading: '犬[いぬ]です。' },
    answer: { expression: '犬です。', expressionReading: '犬[いぬ]です。', meaning: "It's a dog.", notes: label },
  };
  const created = await run('draft-create', [], payload);
  assert.equal(created.id, draftId);
  assert.equal((await run('draft-create', [], payload)).id, draftId);
  if (!media) {
    await failsWith(run('commit', [draftId], { id: cardId }), 409);
    return;
  }
  const draft = await readyDraft(run, draftId);
  assert.equal(draft.answer.expression, payload.answer.expression);
  const card = await run('commit', [draftId], { id: cardId });
  assert.equal(card.syncId, cardId);
  assert.ok(card.prompt.cueAudio, 'Listening card must have prompt audio.');
  assert.equal(card.prompt.cueMeaning, undefined);
  assert.equal(card.answer.expression, payload.answer.expression);
  assert.equal((await run('commit', [draftId], { id: cardId })).syncId, cardId);
  await run('audio', [cardId]);
  return run('card', [cardId]);
}
