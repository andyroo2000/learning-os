import { readFile, stat } from 'node:fs/promises';

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
  const info = await stat(path);
  if (!info.isFile() || (info.mode & 0o077) !== 0) {
    throw new Error('Credential file must be a regular file with mode 600.');
  }
  const profile = JSON.parse(await readFile(path, 'utf8'));
  if (!validToken(profile.token)) {
    throw new Error('Credential file requires a nonempty token.');
  }
  return { baseUrl: apiOrigin(profile.baseUrl), token: profile.token };
}

function validToken(token) {
  if (typeof token !== 'string') return false;
  return token.trim().length > 0 && !/[\r\n]/.test(token);
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
  return response.status === 204 ? { ok: true } : response.json();
}

export function createClient({ baseUrl, token }) {
  const origin = apiOrigin(baseUrl);
  return async (path, { method = 'GET', body, query = {} } = {}) => {
    const url = requestUrl(path, origin, query);
    const response = await fetch(url, {
      method, redirect: 'error', signal: AbortSignal.timeout(30_000),
      headers: { Accept: 'application/json', 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: body === undefined ? undefined : JSON.stringify(body),
    });
    return responseBody(response, method, path);
  };
}
