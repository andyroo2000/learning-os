import test from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createMcpServer } from '../src/mcp/server.js';

const id = '01jzq4nny5xbnzw14q1g68b2yt';
async function connected(t, api) {
  const server = createMcpServer(api);
  const client = new Client({ name: 'authoring-test', version: '1' });
  const [a, b] = InMemoryTransport.createLinkedPair();
  await server.connect(b);
  await client.connect(a);
  t.after(async () => { await client.close(); await server.close(); });
  return client;
}

test('card and draft edits require revisions and preserve complete listening payloads', async t => {
  const calls = [];
  const client = await connected(t, async (path, options) => { calls.push({ path, options }); return {}; });
  const answer = { expression: '日本に行く。', expressionReading: '日本[にほん]に行[い]く。', meaning: 'I will go to Japan.' };
  const prompt = { cueAudio: { id, filename: 'original.mp3' } };
  for (const name of ['update_card', 'update_draft']) {
    assert.equal((await client.callTool({ name, arguments: { id, prompt, answer } })).isError, true);
    assert.notEqual((await client.callTool({ name, arguments: { id: id.toUpperCase(), expectedRevision: 3, prompt, answer } })).isError, true);
  }
  assert.equal(calls.length, 2);
  assert.equal(calls[0].path, `/api/study/cards/${id}`);
  assert.equal(calls[1].path, `/api/study/card-drafts/${id}`);
  assert.deepEqual(calls[0].options.body, { expectedRevision: 3, prompt, answer });
});

test('batch reads are bounded and opaque cursors survive draft/import pagination', async t => {
  const calls = [];
  const client = await connected(t, async (path, options) => { calls.push({ path, options }); return {}; });
  assert.equal((await client.callTool({ name: 'get_cards', arguments: { ids: Array(51).fill(id) } })).isError, true);
  await client.callTool({ name: 'get_cards', arguments: { ids: [id.toUpperCase()] } });
  await client.callTool({ name: 'list_drafts', arguments: { cursor: 'opaque-cursor', limit: 10 } });
  await client.callTool({ name: 'list_imports', arguments: { cursor: 'opaque-cursor', per_page: 10 } });
  assert.deepEqual(calls[0].options, { method: 'POST', body: { ids: [id] } });
  assert.deepEqual(calls[1].options.query, { cursor: 'opaque-cursor', limit: 10 });
  assert.deepEqual(calls[2].options.query, { cursor: 'opaque-cursor', per_page: 10 });
});

test('draft inspection, stable commit and targeted cleanup route to the selected IDs', async t => {
  const calls = [];
  const client = await connected(t, async (path, options) => { calls.push({ path, options }); return {}; });
  await client.callTool({ name: 'get_draft', arguments: { id } });
  await client.callTool({ name: 'commit_draft', arguments: { id, cardId: id } });
  await client.callTool({ name: 'delete_draft', arguments: { id } });
  assert.equal(calls[0].path, `/api/study/card-drafts/${id}`);
  assert.deepEqual(calls[1], { path: `/api/study/card-drafts/${id}/card`, options: { method: 'POST', body: { id } } });
  assert.equal(calls[2].options.method, 'DELETE');
  const { tools } = await client.listTools();
  assert.equal(tools.find(t => t.name === 'delete_draft').annotations.destructiveHint, true);
  assert.equal(tools.find(t => t.name === 'create_import').annotations.idempotentHint, false);
});

test('conflicts and quota responses retain status and retry timing without automatic writes', async t => {
  let calls = 0;
  const client = await connected(t, async () => { calls++; throw Object.assign(new Error('Quota exceeded.'), { status: 429, retryAfter: '20' }); });
  const result = await client.callTool({ name: 'update_card', arguments: { id, expectedRevision: 1, prompt: {}, answer: {} } });
  assert.equal(result.isError, true);
  assert.deepEqual(JSON.parse(result.content[0].text), { message: 'Quota exceeded.', status: 429, retryAfter: '20' });
  assert.equal(calls, 1);
});
