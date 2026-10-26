import { identifier, validateOptions } from './reads.js';
import { jsonInput, requireUlid } from './input.js';

const cards = '/api/study/cards';
const drafts = '/api/study/card-drafts';
export const writeCommands = {
  'card-sources': { path: '/api/study/card-sources', method: 'PUT', payload: true },
  'learn-soon': { path: '/api/study/learn-soon', method: 'PUT', payload: true },
  'book-create': { path: '/api/reading-books', method: 'POST', payload: true, stableId: true },
  'book-edit': { path: '/api/reading-books', method: 'PATCH', id: true, payload: true },
  'book-order': { path: '/api/reading-books', suffix: '/contents', method: 'PUT', id: true, payload: true },
  create: { path: cards, method: 'POST', payload: true, stableId: true },
  edit: { path: cards, method: 'PATCH', id: true, payload: true },
  delete: { path: cards, method: 'DELETE', id: true },
  action: { path: cards, suffix: '/actions', method: 'POST', id: true, payload: true },
  audio: { path: cards, suffix: '/prepare-answer-audio', method: 'POST', id: true },
  'audio-regenerate': { path: cards, suffix: '/regenerate-answer-audio', method: 'POST', id: true, payload: true },
  'draft-create': { path: drafts, method: 'POST', payload: true, stableId: true },
  'draft-edit': { path: drafts, method: 'PATCH', id: true, payload: true },
  'draft-delete': { path: drafts, method: 'DELETE', id: true },
  'draft-retry': { path: drafts, suffix: '/retry', method: 'POST', id: true },
  'draft-audio': { path: drafts, suffix: '/preview-audio', method: 'POST', id: true },
  'draft-image': { path: drafts, suffix: '/preview-image', method: 'POST', id: true },
  commit: { path: drafts, suffix: '/card', method: 'POST', id: true, payload: true, stableId: true },
  link: { path: '/api/cards', suffix: '/learning-path/successor', method: 'PUT', id: true, payload: true },
};

async function payloadFor(command, spec, file) {
  if (!spec.payload) return undefined;
  const body = await jsonInput(file);
  if (spec.stableId) body.id = requireUlid(body.id);
  if (command === 'edit' && !Number.isInteger(body.expectedRevision)) {
    throw new Error('edit requires expectedRevision from the latest card read.');
  }
  if (command === 'link') validateLink(body);
  return body;
}

function validateLink(body) {
  body.successor_card_id = requireUlid(body.successor_card_id);
  if (!['successful_retrieval', 'guru', 'master'].includes(body.unlock_requirement)) {
    throw new Error('link requires an explicit unlock_requirement: successful_retrieval, guru, or master.');
  }
}

export async function writeCommand(api, command, args, values) {
  const spec = writeCommands[command];
  if (args.length !== (spec.id ? 1 : 0)) throw new Error(`Unexpected arguments for ${command}.`);
  validateOptions(command, values, spec.payload ? ['file'] : []);
  const resource = spec.id ? `/${identifier(args[0])}` : '';
  const path = spec.path + resource + (spec.suffix ?? '');
  return api(path, { method: spec.method, body: await payloadFor(command, spec, values.file) });
}
