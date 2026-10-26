import { McpServer } from '@modelcontextprotocol/sdk/server/mcp.js';
import { z } from 'zod';
import { toolDefinitions } from './tools.js';

const result = value => ({ content: [{ type: 'text', text: JSON.stringify(value) }] });

export function createMcpServer(api) {
  const server = new McpServer({ name: 'convolab', version: '0.1.1' }, {
    instructions: 'Discover existing piles first. Save stable IDs before writes and reuse them on retries. Keep imported books and chapters inactive unless the learner chooses them. Book sources and Learn soon are separate; all reviews remain unified.',
  });
  for (const tool of toolDefinitions) {
    server.registerTool(tool.name, {
      description: tool.description,
      inputSchema: z.object(tool.schema).strict(),
      annotations: { readOnlyHint: tool.read === true, destructiveHint: false, idempotentHint: tool.name !== 'new_ids', openWorldHint: !tool.local },
    }, async args => {
      try {
        if (tool.local) return result(tool.local(args));
        const { path, options } = tool.route(args);
        return result(await api(path, options));
      } catch (error) {
        return { isError: true, content: [{ type: 'text', text: error instanceof Error ? error.message : 'ConvoLab request failed.' }] };
      }
    });
  }
  return server;
}
