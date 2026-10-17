import { readFile } from 'node:fs/promises';
import { randomBytes } from 'node:crypto';

export async function jsonInput(file) {
  if (!file) throw new Error('Supply a JSON payload with --file FILE (or --file - for stdin).');
  const text = file === '-' ? await stdinText() : await readFile(file, 'utf8');
  const value = JSON.parse(text);
  if (!isObject(value)) throw new Error('Payload must be a JSON object.');
  return value;
}

async function stdinText() {
  if (process.stdin.isTTY) throw new Error('Pipe a JSON payload to stdin.');
  const chunks = [];
  for await (const chunk of process.stdin) chunks.push(chunk);
  return Buffer.concat(chunks).toString('utf8');
}

export function requireUlid(value) {
  if (typeof value !== 'string' || !/^[0-7][0-9a-hjkmnp-tv-z]{25}$/i.test(value)) {
    throw new Error('A stable ULID is required; generate one with new-id and save it before writing.');
  }
  return value.toLowerCase();
}

export function newId() {
  const alphabet = '0123456789abcdefghjkmnpqrstvwxyz';
  let value = (BigInt(Date.now()) << 80n) | BigInt(`0x${randomBytes(10).toString('hex')}`);
  let id = '';
  for (let index = 0; index < 26; index += 1) {
    id = alphabet[Number(value & 31n)] + id;
    value >>= 5n;
  }
  return { id };
}

function isObject(value) {
  if (value === null) return false;
  return typeof value === 'object' && !Array.isArray(value);
}
