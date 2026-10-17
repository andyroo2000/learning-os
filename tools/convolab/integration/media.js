import assert from 'node:assert/strict';
import { execFile } from 'node:child_process';
import { promisify } from 'node:util';
import { writeFile, unlink } from 'node:fs/promises';
import { join } from 'node:path';
import { loadProfile } from '../src/client.js';

const execute = promisify(execFile);

export async function verifyMedia(profile, directory, reference) {
  assert.match(reference?.id ?? '', /^[0-9a-hjkmnp-tv-z]{26}$/i);
  const { baseUrl, token } = await loadProfile(profile);
  const response = await fetch(`${baseUrl}/api/study/media/${reference.id}`, {
    headers: { Authorization: `Bearer ${token}` }, redirect: 'error', signal: AbortSignal.timeout(30_000),
  });
  assert.equal(response.status, 200, 'Generated audio must stream through the authenticated API.');
  assert.match(response.headers.get('content-type') ?? '', /^audio\//);
  const path = join(directory, `${reference.id}.audio`);
  try {
    await writeFile(path, Buffer.from(await response.arrayBuffer()), { mode: 0o600 });
    const result = await execute('ffprobe', ['-v', 'error', '-show_entries', 'format=duration', '-of', 'json', path]);
    assert.ok(Number(JSON.parse(result.stdout).format.duration) > 0, 'Generated audio must have a playable duration.');
  } finally {
    await unlink(path).catch(error => { if (error.code !== 'ENOENT') throw error; });
  }
}
