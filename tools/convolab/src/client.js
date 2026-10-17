import { readFile, lstat } from 'node:fs/promises';

function parseJson(text) {
  try { return JSON.parse(text); } catch { throw new Error('Invalid JSON received; contents withheld.'); }
}

export function apiOrigin(value) {
  const url = new URL(value);
  const local = ['127.0.0.1', 'localhost', '[::1]'].includes(url.hostname);
  const allowed = local ? ['https:', 'http:'] : ['https:'];
  if (!allowed.includes(url.protocol)) {
    throw new Error('API origin must use HTTPS (HTTP is allowed only on loopback).');
  }
  if ([url.username, url.password, url.search, url.hash, url.pathname.slice(1)].some(Boolean)) {
    throw new Error('API origin must not contain credentials, a path, query, or fragment.');
  }
  return url.origin;
}

export async function loadProfile(path) {
  if (!path) throw new Error('Set CONVOLAB_PROFILE or pass --profile with a private JSON credential file.');
  const info = await lstat(path);
  validateProfileFile(info);
  const profile = parseJson(await readFile(path, 'utf8'));
  if (!validToken(profile.token)) {
    throw new Error('Credential file requires a nonempty token.');
  }
  return { baseUrl: apiOrigin(profile.baseUrl), token: profile.token };
}

function validateProfileFile(info) {
  if (!info.isFile() || (info.mode & 0o077) !== 0) {
    throw new Error('Credential file must be a regular file with mode 600.');
  }
  if (info.uid !== process.getuid()) throw new Error('Credential file must be owned by the current user.');
}

function validToken(token) {
  return typeof token === 'string' && /^(?=.*\S)[^\r\n]+$/.test(token);
}

function requestUrl(path, origin, query) {
  const invalid = [!path.startsWith('/api/'), path.includes('..'), /[?#\\]/.test(path)];
  if (invalid.some(Boolean)) throw new Error('Invalid API path.');
  const url = new URL(path, origin);
  for (const [key, value] of Object.entries(query)) {
    if (value !== undefined) url.searchParams.set(key, value);
  }
  return url;
}

async function responseBody(response, method, path) {
  if (!response.ok) {
    const error = new Error(`API request failed: ${method} ${path} (HTTP ${response.status}).`);
    error.status = response.status;
    error.retryAfter = response.headers.get('retry-after');
    throw error;
  }
  return response.status === 204 ? { ok: true } : parseJson(await response.text());
}

function requestPayload(token, body, multipart) {
  const headers = { Accept: 'application/json', Authorization: `Bearer ${token}` };
  if (multipart) return { headers, body: multipart };
  headers['Content-Type'] = 'application/json';
  return { headers, body: JSON.stringify(body) };
}

async function audioResponse(response, method, path) {
  if (!response.ok) return responseBody(response, method, path);
  if (!/^audio\/mpeg(?:;|$)/.test(response.headers.get('content-type'))) {
    throw new Error('Narration returned an unexpected content type.');
  }
  const bytes = await response.arrayBuffer();
  if (!bytes.byteLength) throw new Error('Narration returned empty audio.');
  return { bytes: bytes.byteLength };
}

export function createClient({ baseUrl, token }) {
  const origin = apiOrigin(baseUrl);
  return async (path, { method = 'GET', body, query = {}, multipart, audio = false, timeout = 30_000 } = {}) => {
    const url = requestUrl(path, origin, query);
    const response = await fetch(url, {
      method, redirect: 'error', signal: AbortSignal.timeout(timeout),
      ...requestPayload(token, body, multipart),
    });
    return audio ? audioResponse(response, method, path) : responseBody(response, method, path);
  };
}
