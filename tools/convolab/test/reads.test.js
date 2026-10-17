import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readCommand } from '../src/reads.js';
import { apiOrigin } from '../src/client.js';

test('search preserves Japanese queries and pagination without inventing mastery', async () => {
  const response = { rows: [{ reviewCount: 0 }], nextCursor: 'next' };
  const api = async (path, options) => {
    assert.equal(path, '/api/study/browser');
    assert.deepEqual(options.query, { q: 'でも', cursor: 'previous', limit: '2' });
    return response;
  };
  assert.equal(await readCommand(api, 'search', [], { q: 'でも', cursor: 'previous', limit: '2' }), response);
});

test('rejects unknown options and unsafe resource IDs before any request', async () => {
  const api = () => assert.fail('must not make a request');
  await assert.rejects(readCommand(api, 'card', ['../me'], {}), /ID is required/);
  await assert.rejects(readCommand(api, 'overview', [], { q: 'ignored' }), /not supported/);
  await assert.rejects(readCommand(api, 'search', ['extra'], {}), /Unexpected/);
});

test('credential destinations require a clean HTTPS origin or local loopback', () => {
  assert.equal(apiOrigin('http://127.0.0.1:8000'), 'http://127.0.0.1:8000');
  for (const value of ['http://example.com', 'https://user:pass@example.com', 'https://example.com/path', 'https://example.com?token=x']) {
    assert.throws(() => apiOrigin(value));
  }
});
