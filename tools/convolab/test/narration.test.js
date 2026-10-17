import assert from 'node:assert/strict';
import { test } from 'node:test';
import { narrateReading } from '../src/narration.js';

test('narration uses the default narrator and only switches the reading after all sentences succeed', async () => {
  const calls = [];
  const api = async (path, options) => {
    calls.push({ path, options });
    if (!options) return { revision: 7, document: { sentences: [{}, {}] } };
    return { ok: true };
  };
  const result = await narrateReading(api, ['reading-id'], {}, { report() {} });
  assert.equal(result.preparedSentences, 2);
  assert.equal(calls[1].options.body.voiceId, 'ナレーター男性');
  assert.equal(calls[2].options.audio, true);
  assert.equal(calls[3].options.method, 'PATCH');
  assert.equal(calls[3].options.body.expectedRevision, 7);
});

test('explicit voices survive bounded quota retries, and a failed run never changes narrator', async () => {
  const pauses = [];
  let attempts = 0;
  const api = async (path, options) => {
    if (!options) return { revision: 1, document: { sentences: [{}] } };
    assert.equal(options.body.voiceId, 'Sato');
    assert.notEqual(options.method, 'PATCH');
    attempts++;
    throw Object.assign(new Error('quota'), { status: 429 });
  };
  await assert.rejects(narrateReading(api, ['reading-id'], { voice: 'Sato' }, {
    pause: async ms => pauses.push(ms), report() {},
  }), /quota/);
  assert.equal(attempts, 4);
  assert.deepEqual(pauses, [61000, 61000, 61000]);
});
