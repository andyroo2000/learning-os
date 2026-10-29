import { z } from 'zod';
import { ulid } from './schemas.js';

const payload = z.record(z.string(), z.unknown());
const revision = z.number().int().min(0);
const cardPath = id => `/api/study/cards/${id}`;
const draftPath = id => `/api/study/card-drafts/${id}`;

export const authoringTools = [
  { name: 'get_cards', description: 'Read up to 50 canonical cards, including answers, presentation, source and revisions. Missing or inaccessible IDs are omitted; compare returned IDs.', read: true,
    schema: { ids: z.array(ulid).min(1).max(50) }, route: args => ({ path: '/api/study/cards/batch', options: { method: 'POST', body: args } }) },
  { name: 'update_card', description: 'Replace complete prompt and answer objects using the revision from get_card. Preserve existing fields you want to retain. A stale revision is rejected; never retry with a newer revision without reviewing it.',
    schema: { id: ulid, expectedRevision: revision, prompt: payload, answer: payload },
    route: ({ id, ...body }) => ({ path: cardPath(id), options: { method: 'PATCH', body } }) },
  { name: 'list_drafts', description: 'List preparation drafts; follow nextCursor until null.', read: true,
    schema: { cursor: z.string().min(1).max(512).optional(), limit: z.number().int().min(1).max(100).optional() }, route: args => ({ path: '/api/study/card-drafts', options: { query: args } }) },
  { name: 'get_draft', description: 'Inspect generation status, complete fields, revision and committedCardId before editing, committing or cleaning up a draft.', read: true,
    schema: { id: ulid }, route: ({ id }) => ({ path: draftPath(id) }) },
  { name: 'update_draft', description: 'Replace both complete draft payloads with revision protection. Preview audio is separate: explicitly include the selected original audio in the prompt before committing a listening card.',
    schema: { id: ulid, expectedRevision: revision, prompt: payload, answer: payload },
    route: ({ id, ...body }) => ({ path: draftPath(id), options: { method: 'PATCH', body } }) },
  { name: 'commit_draft', description: 'Commit a reviewed, ready draft with a saved stable card ID. Reuse that card ID on retries. Inspect the draft first; this tool does not apply preview audio automatically.',
    schema: { id: ulid, cardId: ulid }, route: ({ id, cardId }) => ({ path: `${draftPath(id)}/card`, options: { method: 'POST', body: { id: cardId } } }) },
  { name: 'delete_draft', description: 'Remove an explicitly selected preparation draft. Inspect and archive needed contents first. This does not delete its committed card.', destructive: true,
    schema: { id: ulid }, route: ({ id }) => ({ path: draftPath(id), options: { method: 'DELETE' } }) },
];
