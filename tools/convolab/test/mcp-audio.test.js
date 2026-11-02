import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp, writeFile, rm, truncate, symlink } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createMcpServer } from '../src/mcp/server.js';
import { inspectAudio } from '../src/audio-file.js';

const id = '01jzq4nny5xbnzw14q1g68b2yt';
async function fixture(t, api) {
  const dir = await mkdtemp(join(tmpdir(), 'mcp-audio-'));
  const server = createMcpServer(api);
  const client = new Client({ name: 'audio-test', version: '1.0.0' });
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  await server.connect(serverTransport);
  await client.connect(clientTransport);
  t.after(async () => { await client.close(); await server.close(); await rm(dir, { recursive: true }); });
  const audioPath = join(dir, 'original.wav');
  const bytes = Buffer.alloc(64);
  bytes.write('RIFF'); bytes.write('WAVE', 8);
  await writeFile(audioPath, bytes);
  return { client, dir, bytes, args: { id, audioPath, expectedRevision: 3, ...await inspectAudio(audioPath) } };
}

function uploadArgs(args) {
  const { id, audioPath, expectedRevision, sha256 } = args;
  return { id, audioPath, expectedRevision, sha256 };
}

test('MCP uploads verified bytes with format preservation and revision protection', async t => {
  const calls = [];
  const { client, args, bytes } = await fixture(t, async (path, options) => { calls.push({ path, options }); return { syncId: id, revision: 4 }; });
  const inspected = await client.callTool({ name: 'inspect_audio', arguments: { audioPath: args.audioPath } });
  assert.equal(JSON.parse(inspected.content[0].text).sha256, args.sha256);
  const result = await client.callTool({ name: 'replace_card_audio', arguments: { ...uploadArgs(args), id: id.toUpperCase() } });
  assert.notEqual(result.isError, true);
  assert.equal(calls.length, 1);
  assert.equal(calls[0].path, `/api/study/cards/${id}/audio`);
  assert.equal(calls[0].options.method, 'POST');
  const form = calls[0].options.multipart;
  assert.deepEqual([...form.keys()], ['audio', 'preserveCardFormat', 'expectedRevision']);
  assert.equal(form.get('preserveCardFormat'), '1');
  assert.equal(form.get('expectedRevision'), '3');
  assert.equal(form.get('audio').type, 'audio/wav');
  assert.deepEqual(Buffer.from(await form.get('audio').arrayBuffer()), bytes);
  const { tools } = await client.listTools();
  assert.equal(tools.find(tool => tool.name === 'replace_card_audio').annotations.idempotentHint, false);
});

test('MCP rejects missing revisions, malformed IDs, unknown fields and changed bytes before uploading', async t => {
  const { client, args } = await fixture(t, async () => assert.fail('Unexpected upload'));
  for (const overrides of [{ expectedRevision: undefined }, { expectedRevision: -1 }, { id: '../bad' }, { preserveCardFormat: false }, { sha256: '0'.repeat(64) }]) {
    const result = await client.callTool({ name: 'replace_card_audio', arguments: { ...uploadArgs(args), ...overrides } });
    assert.equal(result.isError, true);
  }
});

test('inspection rejects wrong types, symlinks, non-WAV bytes and oversize files', async t => {
  const { args, dir } = await fixture(t, async () => assert.fail('Unexpected upload'));
  await assert.rejects(inspectAudio('relative.wav'), /absolute/);
  const link = join(dir, 'link.wav');
  await symlink(args.audioPath, link);
  await assert.rejects(inspectAudio(link), /ELOOP/);
  await assert.rejects(inspectAudio(join(dir, 'missing.wav')), /ENOENT/);
  await writeFile(args.audioPath, Buffer.alloc(64));
  await assert.rejects(inspectAudio(args.audioPath), /RIFF/);
  await truncate(args.audioPath, 10 * 1024 * 1024 + 1);
  await assert.rejects(inspectAudio(args.audioPath), /10 MiB/);
  await truncate(args.audioPath, 0);
  await assert.rejects(inspectAudio(args.audioPath), /regular WAV/);
});

test('MCP reports conflicts and uncertain uploads without retrying writes', async t => {
  let attempts = 0;
  const { client, args } = await fixture(t, async () => { attempts += 1; throw Object.assign(new Error('Conflict'), { status: 409 }); });
  const response = await client.callTool({ name: 'replace_card_audio', arguments: uploadArgs(args) });
  assert.equal(response.isError, true);
  assert.equal(JSON.parse(response.content[0].text).status, 409);
  assert.equal(attempts, 1);
});
