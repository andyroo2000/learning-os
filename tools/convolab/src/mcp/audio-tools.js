import { z } from 'zod';
import { ulid } from './schemas.js';
import { inspectAudio, replaceCardAudio } from '../audio-file.js';

const audioPath = z.string().min(1);

function inspectLocalAudio(args) {
  return inspectAudio(args.audioPath);
}

export const audioTools = [
  { name: 'inspect_audio', description: 'Inspect a user-selected local PCM WAV without uploading. Save its SHA-256 before replacing audio; verify that the complete recording matches the card.', read: true,
    schema: { audioPath }, local: inspectLocalAudio },
  { name: 'replace_card_audio', description: 'Replace existing card audio with a verified local WAV and saved SHA-256. Requires the current get_card revision. Preserves text, format, review history and scheduling; replaces answer audio and any existing listening cue. Never retries automatically. After an uncertain result or 409, inspect the card before retrying.', destructive: true, idempotent: false,
    schema: { id: ulid, audioPath, sha256: z.string().regex(/^[a-f0-9]{64}$/), expectedRevision: z.number().int().min(0) },
    execute: replaceCardAudio },
];
