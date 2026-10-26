import { jsonInput, requireUlid } from './input.js';
import { validateOptions } from './reads.js';

export async function savePile(api, command, args, values) {
  validateOptions(command, values, ['file']);
  const chapter = command === 'chapter-save';
  if (args.length !== (chapter ? 2 : 1)) throw new Error('Supply stable pile and, when applicable, chapter IDs.');
  const pileId = requireUlid(args[0]);
  const suffix = chapter ? `/chapters/${requireUlid(args[1])}` : '';
  return api(`/api/study/piles/${pileId}${suffix}`, { method: 'PUT', body: await jsonInput(values.file) });
}
