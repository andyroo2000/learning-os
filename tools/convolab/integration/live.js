import { parseArgs } from 'node:util';
import { mkdir } from 'node:fs/promises';
import { join } from 'node:path';
import { scenario } from './scenario.js';
import { harness, cleanup } from './harness.js';

async function main() {
  const { values } = parseArgs({ options: {
    profile: { type: 'string' }, directory: { type: 'string' },
    execute: { type: 'boolean' }, cleanup: { type: 'boolean' }, media: { type: 'boolean' },
  } });
  if (!values.profile || !values.directory) throw new Error('--profile and --directory are required.');
  if (values.cleanup) {
    await cleanup(harness(values.profile, values.directory), join(values.directory, 'manifest.json'));
    return { cleaned: true };
  }
  if (!values.execute) throw new Error('Pass --execute to create and delete test cards in the selected account.');
  // Exclusive directory creation prevents overwriting the recovery manifest of another run.
  await mkdir(values.directory, { mode: 0o700 });
  return scenario(values.profile, values.directory, undefined, values.media);
}

console.log(JSON.stringify(await main()));
