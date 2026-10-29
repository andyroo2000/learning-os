import { z } from 'zod';
import { ulid } from './schemas.js';
import { inspectArchive, uploadArchive } from '../import-file.js';

const archive = { archivePath: z.string().min(1), sha256: z.string().regex(/^[a-f0-9]{64}$/) };
const jobPath = id => `/api/study/imports/${id}`;

async function resumeImport(api, args) {
  const response = await api(jobPath(args.id));
  const job = response.data;
  if (job.status !== 'pending') return response;
  if (!job.upload_completed_at) {
    await uploadArchive(api, { ...args, filename: job.source_filename });
  }
  return api(`${jobPath(args.id)}/complete`, { method: 'POST', body: {} });
}

export const importTools = [
  { name: 'inspect_import_archive', description: 'Inspect a user-selected local .colpkg without uploading it. Save the returned filename and SHA-256 in the import manifest.', read: true,
    schema: { archivePath: archive.archivePath }, local: args => inspectArchive(args.archivePath) },
  { name: 'create_import', description: 'Create an upload job for a reviewed archive filename. Save the returned job ID before resume_import. Do not blindly retry an uncertain creation: inspect current_import or list_imports to recover its ID.', idempotent: false,
    schema: { filename: z.string().min(1).max(255).regex(/\.colpkg$/i) },
    route: ({ filename }) => ({ path: '/api/study/imports', options: { method: 'POST', body: { filename, content_type: 'application/octet-stream' } } }) },
  { name: 'resume_import', description: 'Upload the verified original archive to a saved job and request completion. Reuse the job ID, path and SHA-256 on retries. Pending uploads restart from byte zero; queued/processing/completed jobs never create duplicates. Returns promptly after queuing; poll get_import for progress. Does not activate stacks.',
    schema: { id: ulid, ...archive }, execute: resumeImport },
  { name: 'get_import', description: 'Read import status, errors, counts and completion. Only completed confirms success.', read: true,
    schema: { id: ulid }, route: ({ id }) => ({ path: jobPath(id) }) },
  { name: 'list_imports', description: 'Find previous import jobs and recover an ID after an uncertain creation response.', read: true,
    schema: { cursor: z.string().min(1).max(512).optional(), per_page: z.number().int().min(1).max(100).optional() }, route: args => ({ path: '/api/study/imports', options: { query: args } }) },
  { name: 'current_import', description: 'Find the active import before starting another or recovering an uncertain request.', read: true,
    schema: {}, route: () => ({ path: '/api/study/imports/current' }) },
  { name: 'cancel_import', description: 'Cancel a selected pending upload. The backend rejects cancellation after processing starts.', destructive: true,
    schema: { id: ulid }, route: ({ id }) => ({ path: `${jobPath(id)}/cancel`, options: { method: 'POST', body: {} } }) },
];
