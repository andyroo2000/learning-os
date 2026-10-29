export function importUploadPayload({ path, method, token, upload }) {
  if (method !== 'PUT') throw new Error('Binary uploads require a study import job.');
  if (!/^\/api\/study\/imports\/[0-9a-z]{26}\/upload$/.test(path)) throw new Error('Binary uploads require a study import job.');
  return {
    headers: { Accept: 'application/json', Authorization: `Bearer ${token}`, 'Content-Type': 'application/octet-stream', 'Content-Length': String(upload.size) },
    body: upload.body, duplex: 'half',
  };
}
