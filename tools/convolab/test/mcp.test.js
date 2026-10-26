import test from 'node:test';
import assert from 'node:assert/strict';
import { Client } from '@modelcontextprotocol/sdk/client/index.js';
import { InMemoryTransport } from '@modelcontextprotocol/sdk/inMemory.js';
import { createMcpServer } from '../src/mcp/server.js';

const id = '01jzq4nny5xbnzw14q1g68b2yt';
async function connected(t, api) {
  const server = createMcpServer(api);
  const client = new Client({ name: 'convolab-test', version: '1.0.0' });
  const [clientTransport, serverTransport] = InMemoryTransport.createLinkedPair();
  await server.connect(serverTransport);
  await client.connect(clientTransport);
  t.after(async () => { await client.close(); await server.close(); });
  return client;
}

test('MCP handshake and discovery expose source schemas and stable-write annotations', async t => {
  const client = await connected(t, async () => ({}));
  const { tools } = await client.listTools();
  const create = tools.find(tool => tool.name === 'create_card');
  assert.equal(create.annotations.idempotentHint, true);
  assert.ok(create.inputSchema.properties.card.properties.bookSource.anyOf.find(schema => schema.type === 'object').properties.chapterId);
  assert.ok(tools.find(tool => tool.name === 'save_chapter'));
  const generated = await client.callTool({ name: 'new_ids', arguments: { count: 2 } });
  const { ids } = JSON.parse(generated.content[0].text);
  assert.equal(new Set(ids).size, 2);
  assert.match(ids[0], /^[0-7][0-9a-hjkmnp-tv-z]{25}$/);
});

test('MCP writes preserve chapter state and source metadata on retries', async t => {
  const calls = [];
  const client = await connected(t, async (path, options) => { calls.push({ path, options }); return { ok: true }; });
  await client.callTool({ name: 'save_chapter', arguments: { pileId: id.toUpperCase(), id, attributes: { title: 'Chapter 20' } } });
  assert.equal(calls[0].path, `/api/study/piles/${id}/chapters/${id}`);
  assert.deepEqual(calls[0].options.body, { title: 'Chapter 20' });
  const card = { id, cardType: 'recognition', prompt: { cueText: '猫' }, answer: { meaning: 'cat' }, bookSource: { pileId: id, chapterId: id, position: 20 }, learnSoon: true };
  for (let attempt = 0; attempt < 2; attempt += 1) {
    const response = await client.callTool({ name: 'create_card', arguments: { card } });
    assert.notEqual(response.isError, true);
  }
  assert.deepEqual(calls[1], calls[2]);
  assert.deepEqual(calls[1].options.body, card);
});

test('MCP rejects malformed source IDs before any network write', async t => {
  let calls = 0;
  const client = await connected(t, async () => { calls += 1; return {}; });
  const response = await client.callTool({ name: 'save_pile', arguments: { id: '../other-user', attributes: { title: 'Book' } } });
  assert.equal(response.isError, true);
  assert.equal(calls, 0);
  const invalid = await client.callTool({ name: 'set_learn_soon', arguments: { cardIds: [id], learnSoon: 'yes' } });
  assert.equal(invalid.isError, true);
  assert.equal(calls, 0);
});

test('MCP returns API failures as tool errors and preserves clearing semantics', async t => {
  const calls = [];
  const client = await connected(t, async (path, options) => { calls.push(options); if (path.endsWith('learn-soon')) throw new Error('API request failed (HTTP 429).'); return { changed: 1 }; });
  const assigned = await client.callTool({ name: 'assign_card_sources', arguments: { cardIds: [id], bookSource: null } });
  assert.deepEqual(calls[0].body, { cardIds: [id], bookSource: null });
  assert.equal(JSON.parse(assigned.content[0].text).changed, 1);
  const failed = await client.callTool({ name: 'set_learn_soon', arguments: { cardIds: [id], learnSoon: false } });
  assert.equal(failed.isError, true);
  assert.match(failed.content[0].text, /429/);
});
