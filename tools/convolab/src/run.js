import { parseArgs } from 'node:util';
import { createClient, loadProfile } from './client.js';
import { readCommand, readCommands } from './reads.js';

export async function run(argv) {
  const { values, positionals } = parseArgs({
    args: argv, allowPositionals: true,
    options: Object.fromEntries(['profile', 'q', 'cursor', 'limit', 'card_id', 'per_page'].map(key => [key, { type: 'string' }])),
  });
  const [command = 'help', ...args] = positionals;
  if (command === 'help') {
    return { commands: Object.keys(readCommands), usage: 'convolab COMMAND [ID] [--profile FILE] [--q TEXT] [--cursor CURSOR]', pagination: 'Responses retain API cursors; continue until the next cursor is null.' };
  }
  const api = createClient(await loadProfile(values.profile ?? process.env.CONVOLAB_PROFILE));
  return readCommand(api, command, args, values);
}
