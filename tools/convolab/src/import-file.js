import { open } from 'node:fs/promises';
import { createHash } from 'node:crypto';
import { basename, isAbsolute, extname } from 'node:path';
import { Readable } from 'node:stream';

async function archiveHandle(path) {
  if (!isAbsolute(path) || extname(path).toLowerCase() !== '.colpkg') throw new Error('Select an absolute path to a .colpkg archive.');
  const handle = await open(path, 'r');
  try {
    const stat = await handle.stat();
    if (!validArchiveSize(stat)) throw new Error('Archive must be a nonempty regular file no larger than 2 GiB.');
    return { handle, filename: basename(path), bytes: stat.size };
  } catch (error) {
    await handle.close();
    throw error;
  }
}

function validArchiveSize(stat) {
  if (!stat.isFile()) return false;
  return stat.size >= 1 && stat.size <= 2_147_483_648;
}

async function digest(handle) {
  const hash = createHash('sha256');
  for await (const chunk of handle.createReadStream({ start: 0, autoClose: false })) hash.update(chunk);
  return hash.digest('hex');
}

export async function inspectArchive(path) {
  const file = await archiveHandle(path);
  try { return { filename: file.filename, bytes: file.bytes, sha256: await digest(file.handle) }; }
  finally { await file.handle.close(); }
}

export async function uploadArchive(api, args) {
  const file = await archiveHandle(args.archivePath);
  try {
    if (await digest(file.handle) !== args.sha256) throw new Error('Archive checksum differs from the saved manifest.');
    if (file.filename !== args.filename) throw new Error('Archive filename differs from the import job.');
    return await transferArchive(api, args.id, file, args.sha256);
  } finally { await file.handle.close(); }
}

async function transferArchive(api, id, file, expectedHash) {
  const hash = createHash('sha256');
  const body = Readable.from(hashChunks(file.handle, hash));
  try {
    const result = await api(`/api/study/imports/${id}/upload`, {
      method: 'PUT', upload: { body, size: file.bytes }, timeout: 600_000,
    });
    if (hash.digest('hex') !== expectedHash) throw new Error('Archive changed during upload; completion was not requested.');
    return result;
  } finally { body.destroy(); }
}

async function* hashChunks(handle, hash) {
  for await (const chunk of handle.createReadStream({ start: 0, autoClose: false })) {
    hash.update(chunk);
    yield chunk;
  }
}
