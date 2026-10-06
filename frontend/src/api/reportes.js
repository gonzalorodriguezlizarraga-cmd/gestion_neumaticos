import { api } from './client';
import { tokenStore } from '../auth/storage';

const API_URL = (import.meta.env.VITE_API_URL ?? '').replace(/\/$/, '');

function query(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') search.set(key, String(value));
  });
  const text = search.toString();
  return text ? `?${text}` : '';
}

export const consultarReporte = (tipo, params) => api(`/api/v1/reportes/${tipo}${query(params)}`);

export async function descargarReporte(tipo, formato, params) {
  const headers = {};
  const token = tokenStore.get();
  if (token) headers.Authorization = `Bearer ${token}`;
  const response = await fetch(`${API_URL}/api/v1/reportes/${tipo}/${formato}${query(params)}`, { headers });
  if (response.status === 401) {
    tokenStore.clear();
    window.dispatchEvent(new CustomEvent('auth:unauthorized'));
  }
  if (!response.ok) {
    return { ok: false, status: response.status };
  }
  const blob = await response.blob();
  const disposition = response.headers.get('Content-Disposition') || '';
  const encontrado = disposition.match(/filename="([^"]+)"/);
  const nombre = encontrado?.[1] || `${tipo}.${formato}`;
  const url = URL.createObjectURL(blob);
  const enlace = document.createElement('a');
  enlace.href = url;
  enlace.download = nombre;
  enlace.click();
  URL.revokeObjectURL(url);
  return { ok: true, status: response.status, nombre };
}
