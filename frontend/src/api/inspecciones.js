import { api, apiBlob, apiForm } from './client';

function query(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') {
      search.set(key, String(value));
    }
  });
  const text = search.toString();
  return text ? `?${text}` : '';
}

export function pagina(result) {
  if (!result.ok) {
    return { ok: false, rows: [], total: 0, message: result.payload?.err?.message || 'No se pudo consultar.' };
  }
  return { ok: true, rows: result.payload?.data ?? [], total: result.payload?.total ?? 0 };
}

export const listInspecciones = (params) => api(`/api/v1/inspecciones${query(params)}`);
export const getInspeccion = (id) => api(`/api/v1/inspecciones/${id}`);
export const createInspeccion = (body) => api('/api/v1/inspecciones', { method: 'POST', body });
export const updateInspeccion = (id, body) => api(`/api/v1/inspecciones/${id}`, { method: 'PUT', body });
export const finalizarInspeccion = (id) => api(`/api/v1/inspecciones/${id}/finalizar`, { method: 'POST', body: {} });
export const crearDetalle = (id, body) => api(`/api/v1/inspecciones/${id}/detalles`, { method: 'POST', body });
export const actualizarDetalle = (id, detalleId, body) => api(`/api/v1/inspecciones/${id}/detalles/${detalleId}`, { method: 'PUT', body });
export const listTiposDano = () => api('/api/v1/tipos-dano');
export const agregarDano = (id, detalleId, body) => api(`/api/v1/inspecciones/${id}/detalles/${detalleId}/danos`, { method: 'POST', body });
export const actualizarDano = (id, detalleId, tipoId, body) => api(`/api/v1/inspecciones/${id}/detalles/${detalleId}/danos/${tipoId}`, { method: 'PUT', body });
export const quitarDano = (id, detalleId, tipoId) => api(`/api/v1/inspecciones/${id}/detalles/${detalleId}/danos/${tipoId}`, { method: 'DELETE' });
export const subirFotoInspeccion = (id, file) => apiForm(`/api/v1/inspecciones/${id}/archivos`, file);
export const subirFotoDetalle = (id, detalleId, file) => apiForm(`/api/v1/inspecciones/${id}/detalles/${detalleId}/archivos`, file);
export const bajarArchivo = (id) => apiBlob(`/api/v1/archivos/${id}/download`);
export const eliminarArchivo = (id) => api(`/api/v1/archivos/${id}`, { method: 'DELETE' });
export const listInspeccionesNeumatico = (id, params) => api(`/api/v1/neumaticos/${id}/inspecciones${query(params)}`);
export const listInspeccionesUnidad = (id, params) => api(`/api/v1/unidades/${id}/inspecciones${query(params)}`);
