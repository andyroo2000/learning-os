import { open } from 'node:fs/promises';
import { constants } from 'node:fs';
import { createHash } from 'node:crypto';
import { basename, extname, isAbsolute } from 'node:path';

// Matches PersistUploadedStudyAudioAction; the API validates PCM and duration.
const maxBytes = 10 * 1024 * 1024;

async function readAudio(path) {
  if (!isAbsolute(path) || extname(path).toLowerCase() !== '.wav') {
    throw new Error('Select an absolute path to a PCM .wav file.');
  }
  const handle = await open(path, constants.O_RDONLY | constants.O_NOFOLLOW | constants.O_NONBLOCK);
  try {
    const stat = await handle.stat();
    if (!validAudioFile(stat)) {
      throw new Error('Audio must be a regular WAV file no larger than 10 MiB.');
    }
    // Bounded snapshot: the hash and uploaded bytes must be identical even if the file changes.
    const bytes = await snapshotBytes(handle, stat.size);
    validateWav(bytes);
    return bytes;
  } finally { await handle.close(); }
}

async function snapshotBytes(handle, size) {
  const chunks = [];
  for await (const chunk of handle.createReadStream({ start: 0, end: size, autoClose: false })) {
    chunks.push(chunk);
  }
  const bytes = Buffer.concat(chunks);
  if (bytes.length !== size) throw new Error('Audio changed size while being read.');
  return bytes;
}

function validAudioFile(stat) {
  if (!stat.isFile()) return false;
  return stat.size >= 44 && stat.size <= maxBytes;
}

function validateWav(bytes) {
  if (bytes.length < 44 || bytes.length > maxBytes) throw new Error('Invalid WAV size.');
  if (bytes.toString('ascii', 0, 4) !== 'RIFF' || bytes.toString('ascii', 8, 12) !== 'WAVE') {
    throw new Error('Audio must contain RIFF/WAVE bytes.');
  }
}

function metadata(path, bytes) {
  return { filename: basename(path), bytes: bytes.length, sha256: createHash('sha256').update(bytes).digest('hex') };
}

export async function inspectAudio(path) {
  return metadata(path, await readAudio(path));
}

export async function replaceCardAudio(api, args) {
  const bytes = await readAudio(args.audioPath);
  const inspected = metadata(args.audioPath, bytes);
  if (inspected.sha256 !== args.sha256) throw new Error('Audio checksum differs from the saved manifest.');
  const multipart = new FormData();
  multipart.set('audio', new Blob([bytes], { type: 'audio/wav' }), inspected.filename);
  multipart.set('preserveCardFormat', '1');
  multipart.set('expectedRevision', String(args.expectedRevision));
  return api(`/api/study/cards/${args.id}/audio`, { method: 'POST', multipart, timeout: 120_000 });
}
