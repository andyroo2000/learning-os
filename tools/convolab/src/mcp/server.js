import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { toolDefinitions } from './tools.js';
import { authoringTools } from './authoring-tools.js';
import { importTools } from './import-tools.js';

const result = value => ({ content: [{ type: 'text', text: JSON.stringify(value) }] });

export function createMcpServer(api) {
  const server = new McpServer({ name: 'convolab', version: '0.2.0' }, {
    instructions: 'Discover existing piles first. Save stable IDs before writes and reuse them on retries. Keep imported books and chapters inactive unless the learner chooses them. Book sources and Learn soon are separate; all reviews remain unified.',
  });
  for (const tool of [...toolDefinitions, ...authoringTools, ...importTools]) {
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: z.object(tool.schema).strict(),
      annotations: { readOnlyHint: tool.read === true, destructiveHint: tool.destructive === true, idempotentHint: tool.idempotent ?? tool.name !== 'new_ids', openWorldHint: !tool.local },
    }, async args => {
      try {
        if (tool.local) return result(await tool.local(args));
        if (tool.execute) return result(await tool.execute(api, args));
        const { path, options } = tool.route(args);
        return result(await api(path, options));
      } catch (error) {
        return { ...result({ message: error instanceof Error ? error.message : 'ConvoLab request failed.', status: error?.status ?? null, retryAfter: error?.retryAfter ?? null }), isError: true };
      }
    });
  }
  return server;
}
