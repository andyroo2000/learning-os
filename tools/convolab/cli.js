#!/usr/bin/env node
import { run } from './src/run.js';

async function main() {
try {
  const result = await run(process.argv.slice(2));
  process.stdout.write(`${JSON.stringify(result, null, 2)}\n`);
} catch (error) {
  process.stderr.write(`${JSON.stringify({ error: error.message, status: error.status, retryAfter: error.retryAfter })}\n`);
  process.exitCode = 1;
}
}

await main();
