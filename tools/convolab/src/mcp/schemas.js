import { z } from 'zod';

export const ulid = z.string().regex(/^[0-7][0-9A-HJKMNP-TV-Za-hjkmnp-tv-z]{25}$/, 'Use a saved, stable ULID.').transform(value => value.toLowerCase());
export const bookSource = z.object({
  pileId: ulid,
  chapterId: ulid.nullable().optional(),
  position: z.number().int().min(0).max(1_000_000).nullable().optional(),
  reference: z.string().max(512).nullable().optional(),
}).strict();
export const pileAttributes = z.object({
  title: z.string().min(1).max(160).optional(),
  position: z.number().int().min(0).max(1_000_000).optional(),
  source_reference: z.string().max(512).nullable().optional(),
  is_active: z.boolean().optional(),
}).strict();
export const card = z.object({
  id: ulid,
  cardType: z.enum(['recognition', 'production', 'cloze']),
  prompt: z.record(z.string(), z.unknown()),
  answer: z.record(z.string(), z.unknown()),
  bookSource: bookSource.nullable().optional(),
  learnSoon: z.boolean().optional(),
}).passthrough();
export const pagination = {
  cursor: z.number().int().min(0).optional(),
  limit: z.number().int().min(1).max(100).optional(),
};
