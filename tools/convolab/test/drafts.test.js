import test from 'node:test';
import assert from 'node:assert/strict';
import { verifyDraft } from '../integration/drafts.js';

function fixture({ status = 'ready', role = 'prompt', editError } = {}) {
  let draft;
  let edited;
  const calls = [];
  const previewAudio = { id: 'managed-audio', url: '/api/study/media/managed-audio' };
  const handlers = {
    'draft-create': (_args, body) => { draft = { ...body, status, revision: 1 }; return draft; },
    draft: () => draft,
    'draft-audio': () => { draft = { ...draft, revision: 2, previewAudio, previewAudioRole: role }; return draft; },
    'draft-edit': (_args, body) => {
      if (editError) throw editError;
      assert.equal(body.expectedRevision, 2);
      edited = body;
      return body;
    },
    commit: () => ({ syncId: 'card-id', prompt: edited?.prompt ?? {}, answer: draft.answer }),
    audio: () => ({}),
    card: () => ({ syncId: 'card-id' }),
  };
  return { calls, previewAudio, edit: () => edited, run: async (command, args, body) => {
    calls.push(command);
    return handlers[command](args, body);
  } };
}

const options = { draftId: 'draft-id', cardId: 'card-id', label: 'test', media: true };

test('listening workflow explicitly generates and applies preview audio before commit', async () => {
  const f = fixture();
  await verifyDraft(f.run, options);
  assert.ok(f.calls.indexOf('draft-edit') < f.calls.indexOf('commit'));
  assert.deepEqual(f.edit().prompt, { cueAudio: f.previewAudio });
  assert.deepEqual(f.edit().answer.answerAudio, f.previewAudio);
  assert.equal(f.edit().answer.expression, '犬です。');
  assert.equal(f.edit().answer.meaning, "It's a dog.");
});

test('an answer-role preview cannot become a listening prompt', async () => {
  const f = fixture({ role: 'answer' });
  await assert.rejects(verifyDraft(f.run, options));
  assert.ok(!f.calls.includes('commit'));
});

test('concurrent draft changes prevent committing stale audio fields', async () => {
  const error = new Error('Revision conflict');
  const f = fixture({ editError: error });
  await assert.rejects(verifyDraft(f.run, options), error);
  assert.ok(!f.calls.includes('commit'));
});

test('the API error state stops polling without requesting audio', async () => {
  const f = fixture({ status: 'error' });
  await assert.rejects(verifyDraft(f.run, options), /Draft generation failed/);
  assert.ok(!f.calls.includes('draft-audio'));
});
