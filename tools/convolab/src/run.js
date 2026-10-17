import { homedir } from 'node:os';
import { join } from 'node:path';
import { parseArgs } from 'node:util';
import { createClient, loadProfile } from './client.js';
import { readCommand, readCommands } from './reads.js';
import { writeCommand, writeCommands } from './writes.js';
import { newId } from './input.js';
import { importReading } from './readings.js';
import { narrateReading } from './narration.js';

export async function run(argv) {
  const { values, positionals } = parseArgs({
    args: argv, allowPositionals: true,
    options: Object.fromEntries(['voice', 'page', 'profile', 'q', 'cursor', 'limit', 'card_id', 'per_page', 'file'].map(key => [key, { type: 'string' }])),
  });
  const [command = 'help', ...args] = positionals;
  if (command === 'help') {
    return { commands: ['new-id', 'reading-import', 'reading-narrate', ...Object.keys(readCommands), ...Object.keys(writeCommands)], usage: 'convolab COMMAND [ID] [--profile FILE] [--file FILE|-] [--q TEXT] [--cursor CURSOR]', pagination: 'Responses retain API cursors; continue until the next cursor is null.' };
  }
  if (command === 'new-id') return newId();
  const api = createClient(await loadProfile(values.profile ?? process.env.CONVOLAB_PROFILE ?? join(homedir(), '.config/convolab/profile.json')));
  if (command === 'reading-narrate') return narrateReading(api, args, values);
  if (command === 'reading-import') return importReading(api, args, values);
  if (Object.hasOwn(writeCommands, command)) return writeCommand(api, command, args, values);
  return readCommand(api, command, args, values);
}
