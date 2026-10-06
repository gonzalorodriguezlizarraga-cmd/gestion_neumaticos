import { tokenStore } from '../auth/storage';

const API_URL = (import.meta.env.VITE_API_URL ?? '').replace(/\/$/, '');

export async function api(path, { method = 'GET', body, auth = true } = {}) {
  const headers = { Accept: 'application/json' };
  if (body !== undefined) {
    headers['Content-Type'] = 'application/json';
  }
  if (auth) {
    const token = tokenStore.get();
    if (token) {
      headers.Authorization = `Bearer ${token}`;
    }
  }

  const response = await fetch(`${API_URL}${path}`, {
    method,
    headers,
    body: body !== undefined ? JSON.stringify(body) : undefined,
  });

  let payload = null;
  const text = await response.text();
  if (text !== '') {
    try {
      payload = JSON.parse(text);
    } catch {
      payload = null;
    }
  }

  if (response.status === 401 && auth) {
    tokenStore.clear();
    window.dispatchEvent(new CustomEvent('auth:unauthorized'));
  }

  return {
    ok: response.ok,
    status: response.status,
    payload,
  };
}
