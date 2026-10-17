import { setTimeout as delay } from 'node:timers/promises';
import { identifier, validateOptions } from './reads.js';

async function prepareSentence(api, path, voice, pause) {
  for (let attempt = 0; attempt < 4; attempt++) {
    try {
      return await api(path, { method: 'POST', body: { voiceId: voice }, audio: true, timeout: 120_000 });
    } catch (error) {
      if (![409, 429].includes(error.status) || attempt === 3) throw error;
      const seconds = error.status === 409 ? 6 : 61;
      await pause(seconds * 1000);
    }
  }
}

export async function narrateReading(api, args, values, { pause = delay, report = line => process.stderr.write(`${line}\n`) } = {}) {
  validateOptions('reading-narrate', values, ['voice']);
  if (args.length !== 1) throw new Error('reading-narrate requires a reading ID.');
  const path = `/api/convolab/readings/${identifier(args[0])}`;
  const reading = await api(path);
  const voice = values.voice ?? 'ナレーター男性';
  const sentences = reading.document.sentences;
  for (let index = 0; index < sentences.length; index++) {
    await prepareSentence(api, `${path}/sentences/${index}/audio`, voice, pause);
    report(`Prepared ${index + 1}/${sentences.length} sentences.`);
  }
  const result = await api(`${path}/narration`, {
    method: 'PATCH', body: { voiceId: voice, expectedRevision: reading.revision },
  });
  return { ...result, preparedSentences: sentences.length };
}
