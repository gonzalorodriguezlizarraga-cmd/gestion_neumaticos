import { api } from './client';

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

export const listTipos = (params) => api(`/api/v1/tipos-unidad${query(params)}`);
export const getTipo = (id) => api(`/api/v1/tipos-unidad/${id}`);
export const createTipo = (body) => api('/api/v1/tipos-unidad', { method: 'POST', body });
export const updateTipo = (id, body) => api(`/api/v1/tipos-unidad/${id}`, { method: 'PUT', body });
export const changeTipoActivo = (id, activo) => api(`/api/v1/tipos-unidad/${id}/estado`, { method: 'PATCH', body: { activo } });

export const listConfiguraciones = (params) => api(`/api/v1/configuraciones-unidad${query(params)}`);
export const getConfiguracion = (id) => api(`/api/v1/configuraciones-unidad/${id}`);
export const createConfiguracion = (body) => api('/api/v1/configuraciones-unidad', { method: 'POST', body });
export const updateConfiguracion = (id, body) => api(`/api/v1/configuraciones-unidad/${id}`, { method: 'PUT', body });
export const duplicateConfiguracion = (id, nombre) => api(`/api/v1/configuraciones-unidad/${id}/duplicar`, { method: 'POST', body: { nombre } });
export const changeConfiguracionActivo = (id, activo) => api(`/api/v1/configuraciones-unidad/${id}/estado`, { method: 'PATCH', body: { activo } });

export const listUnidades = (params) => api(`/api/v1/unidades${query(params)}`);
export const getUnidad = (id) => api(`/api/v1/unidades/${id}`);
export const createUnidad = (body) => api('/api/v1/unidades', { method: 'POST', body });
export const updateUnidad = (id, body) => api(`/api/v1/unidades/${id}`, { method: 'PUT', body });
export const changeUnidadEstado = (id, estado) => api(`/api/v1/unidades/${id}/estado`, { method: 'PATCH', body: { estado } });
export const deleteUnidad = (id) => api(`/api/v1/unidades/${id}`, { method: 'DELETE' });
