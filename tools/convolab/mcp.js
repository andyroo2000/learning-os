#!/usr/bin/env node
import { homedir } from 'node:os';
import { join } from 'node:path';
import { parseArgs } from 'node:util';
import { StdioServerTransport } from '@modelcontextprotocol/sdk/server/stdio.js';
import { createClient, loadProfile } from './src/client.js';
import { createMcpServer } from './src/mcp/server.js';

const { values } = parseArgs({ options: { profile: { type: 'string' } } });
const profilePath = values.profile ?? process.env.CONVOLAB_PROFILE ?? join(homedir(), '.config/convolab/profile.json');
let api;
const server = createMcpServer(async (path, options) => {
  api ??= createClient(await loadProfile(profilePath));
  return api(path, options);
});
await server.connect(new StdioServerTransport());
