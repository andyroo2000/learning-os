export const readCommands = {
  whoami: { path: '/api/me' },
  overview: { path: '/api/study/overview' },
  search: { path: '/api/study/browser', query: ['q', 'cursor', 'limit'] },
  note: { path: '/api/study/browser/', id: true },
  card: { path: '/api/study/cards/', id: true },
  drafts: { path: '/api/study/card-drafts', query: ['cursor', 'limit'] },
  draft: { path: '/api/study/card-drafts/', id: true },
  reviews: { path: '/api/card-review-events', query: ['card_id', 'cursor', 'per_page'] },
  path: { path: '/api/cards/', id: true, suffix: '/learning-path' },
};

export function identifier(value) {
  if (typeof value !== 'string' || !/^[a-zA-Z0-9-]+$/.test(value)) {
    throw new Error('A card, note, or draft ID is required.');
  }
  return value;
}

export async function readCommand(api, command, args, values) {
  const spec = readCommands[command];
  if (!spec) throw new Error(`Unknown command: ${command}`);
  if (args.length !== (spec.id ? 1 : 0)) throw new Error(`Unexpected arguments for ${command}.`);
  validateOptions(command, values, spec.query);
  const path = spec.path + (spec.id ? identifier(args[0]) : '') + (spec.suffix ?? '');
  const query = Object.fromEntries((spec.query ?? []).map(key => [key, values[key]]));
  return api(path, { query });
}

export function validateOptions(command, values, query = []) {
  const allowed = new Set(['profile', ...query]);
  for (const key of Object.keys(values)) {
    if (!allowed.has(key)) throw new Error(`Option --${key} is not supported by ${command}.`);
  }
}
