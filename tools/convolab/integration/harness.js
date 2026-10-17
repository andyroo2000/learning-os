import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { readFile, writeFile } from 'node:fs/promises';
import { join } from 'node:path';

const execute = promisify(execFile);
const cli = new URL('../cli.js', import.meta.url).pathname;

export function harness(profile, directory) {
  let sequence = 0;
  async function run(command, args = [], payload) {
    const argv = [cli, command, ...args, '--profile', profile];
    if (payload !== undefined) {
      const file = join(directory, `payload-${sequence++}.json`);
      await writeFile(file, JSON.stringify(payload), { mode: 0o600 });
      argv.push('--file', file);
    }
    const { stdout } = await execute(process.execPath, argv, { maxBuffer: 8 * 1024 * 1024 });
    return JSON.parse(stdout);
  }
  return run;
}

export async function failsWith(operation, status) {
  await assert.rejects(operation, error => {
    assert.equal(JSON.parse(error.stderr).status, status);
    return true;
  });
}

export async function cleanup(run, manifestPath) {
  const manifest = JSON.parse(await readFile(manifestPath, 'utf8'));
  assert.equal((await run('whoami')).data.id, manifest.accountId, 'Cleanup profile must match the creating account.');
  const failures = [];
  for (const id of manifest.drafts) {
    try { await removeResource(run, 'draft', id); } catch { failures.push(id); }
  }
  for (const id of manifest.cards) {
    try { await removeResource(run, 'card', id); } catch { failures.push(id); }
  }
  if (failures.length) throw new Error(`Cleanup failed; retain manifest and retry IDs: ${failures.join(', ')}`);
  await writeFile(manifestPath, JSON.stringify({ ...manifest, cleaned: true }), { mode: 0o600 });
}

async function removeResource(run, kind, id) {
  const command = kind === 'card' ? 'delete' : 'draft-delete';
  try { await run(command, [id]); } catch (error) {
    if (JSON.parse(error.stderr).status !== 404) throw error;
  }
  await failsWith(run(kind, [id]), 404);
  // Retrying an already-deleted resource may return 404 or succeed, but must not recreate it.
  try { await run(command, [id]); } catch (error) {
    if (JSON.parse(error.stderr).status !== 404) throw error;
  }
}
