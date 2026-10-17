import { readFile, stat } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { jsonInput, requireUlid } from './input.js';
import { validateOptions } from './reads.js';

async function appendImage(form, name, path, budget) {
  const info = await stat(path);
  if (!info.isFile() || info.size > 10_000_000) throw new Error('Images must be regular files under 10 MB.');
  budget.bytes += info.size;
  if (budget.bytes > 24_000_000) throw new Error('Import bundle exceeds 24 MB.');
  const bytes = await readFile(path);
  form.append(name, new Blob([bytes]), 'illustration');
}

async function appendPageImages(form, document, directory, budget) {
  if (!Array.isArray(document.pages)) throw new Error('Document pages must be a list.');
  for (const page of document.pages) {
    if (page.imageFile !== undefined) {
      if (typeof page.imageFile !== 'string') throw new Error('imageFile must be a local filename.');
      await appendImage(form, `images[${page.number}]`, resolve(directory, page.imageFile), budget);
      delete page.imageFile;
    }
  }
}

export async function importReading(api, args, values) {
  validateOptions('reading-import', values, ['file']);
  if ([args.length, !values.file, values.file === '-'].some(Boolean)) throw new Error('reading-import requires --file with a local manifest filename.');
  const form = await importForm(values.file);
  return api('/api/reading-imports', { method: 'POST', multipart: form });
}

async function importForm(file) {
  const input = await jsonInput(file);
  const id = requireUlid(input.id);
  const document = input.document;
  if (!document || typeof document !== 'object') throw new Error('Manifest requires a document.');
  const directory = dirname(resolve(file));
  const form = new FormData();
  const budget = { bytes: Buffer.byteLength(JSON.stringify(document)) };
  if (budget.bytes > 24_000_000) throw new Error('Import bundle exceeds 24 MB.');
  await appendPageImages(form, document, directory, budget);
  if (input.coverFile) await appendImage(form, 'cover', resolve(directory, input.coverFile), budget);
  form.append('id', id);
  form.append('document', JSON.stringify(document));
  if (input.expectedRevision !== undefined) form.append('expectedRevision', String(input.expectedRevision));
  return form;
}
