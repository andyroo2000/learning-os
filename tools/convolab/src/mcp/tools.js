import { z } from 'zod';
import { newId, requireUlid } from '../input.js';
import { bookSource, card, pagination, pileAttributes, ulid } from './schemas.js';

const cardIds = z.array(ulid).min(1).max(100);
const request = (path, method, body) => ({ path, options: { method, body } });
const encodedId = value => encodeURIComponent(requireUlid(value));

export const toolDefinitions = [
  { name: 'new_ids', description: 'Generate and save these stable IDs before creating piles, chapters, cards, or drafts. Reuse them on retries.', read: true,
    schema: { count: z.number().int().min(1).max(50).default(1) }, local: ({ count }) => ({ ids: Array.from({ length: count }, () => newId().id) }) },
  { name: 'list_piles', description: 'List owned books, chapter switches, and available-card counts. Includes parked books. Follow next_cursor until null.', read: true,
    schema: pagination, route: args => ({ path: '/api/study/piles', options: { query: args } }) },
  { name: 'save_pile', description: 'Create or sparsely update a book with a stable ID. New books default inactive. Set is_active explicitly only when the learner wants to draw from it.',
    schema: { id: ulid, attributes: pileAttributes }, route: ({ id, attributes }) => request(`/api/study/piles/${encodedId(id)}`, 'PUT', attributes) },
  { name: 'save_chapter', description: 'Create or update a chapter under its book. New chapters default inactive; position gives textbook order. IDs cannot move between books.',
    schema: { pileId: ulid, id: ulid, attributes: pileAttributes }, route: ({ pileId, id, attributes }) => request(`/api/study/piles/${encodedId(pileId)}/chapters/${encodedId(id)}`, 'PUT', attributes) },
  { name: 'create_card', description: 'Create a study card with a saved stable ID. Include bookSource for book/chapter/order/reference and learnSoon for personal priority. Existing variant metadata is also accepted.',
    schema: { card }, route: args => request('/api/study/cards', 'POST', args.card) },
  { name: 'create_draft', description: 'Create a generation draft with a saved stable ID, creationKind, prompt, answer, and optional bookSource / learnSoon. Sources survive generation and card commit.',
    schema: { draft: card.extend({ creationKind: z.enum(['text-recognition', 'audio-recognition', 'production-text', 'production-image', 'cloze']) }) },
    route: ({ draft }) => request('/api/study/card-drafts', 'POST', draft) },
  { name: 'assign_card_sources', description: 'Atomically categorize up to 100 existing canonical card IDs. Use real import/deck/chapter evidence; do not infer a book from vocabulary alone. Null clears the assignment. Reviews are unchanged.',
    schema: { cardIds, bookSource: bookSource.nullable() }, route: args => request('/api/study/card-sources', 'PUT', args) },
  { name: 'set_learn_soon', description: 'Set or clear persistent priority on new cards without changing their original source or lane. Paused books/chapters remain paused.',
    schema: { cardIds, learnSoon: z.boolean() }, route: args => request('/api/study/learn-soon', 'PUT', args) },
  { name: 'search_cards', description: 'Search the card browser for existing cards before creating duplicates. Inspect note/card detail for source evidence.', read: true,
    schema: { ...pagination, q: z.string().max(500).optional() }, route: args => ({ path: '/api/study/browser', options: { query: args } }) },
  { name: 'get_card', description: 'Read a card, its canonical syncId, source metadata, and current scheduling state.', read: true,
    schema: { id: ulid }, route: ({ id }) => ({ path: `/api/study/cards/${encodedId(id)}` }) },
  { name: 'new_queue', description: 'Inspect existing unintroduced cards for source categorization. This management list is not the weighted lesson order.', read: true,
    schema: { ...pagination, q: z.string().max(500).optional() }, route: args => ({ path: '/api/study/new-queue', options: { query: args } }) },
];
