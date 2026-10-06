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

export const listMarcas = (params) => api(`/api/v1/marcas-neumatico${query(params)}`);
export const createMarca = (body) => api('/api/v1/marcas-neumatico', { method: 'POST', body });
export const updateMarca = (id, body) => api(`/api/v1/marcas-neumatico/${id}`, { method: 'PUT', body });
export const changeMarcaActivo = (id, activo) => api(`/api/v1/marcas-neumatico/${id}/estado`, { method: 'PATCH', body: { activo } });

export const listModelos = (params) => api(`/api/v1/modelos-neumatico${query(params)}`);
export const createModelo = (body) => api('/api/v1/modelos-neumatico', { method: 'POST', body });
export const updateModelo = (id, body) => api(`/api/v1/modelos-neumatico/${id}`, { method: 'PUT', body });
export const changeModeloActivo = (id, activo) => api(`/api/v1/modelos-neumatico/${id}/estado`, { method: 'PATCH', body: { activo } });

export const listMedidas = (params) => api(`/api/v1/medidas-neumatico${query(params)}`);
export const createMedida = (body) => api('/api/v1/medidas-neumatico', { method: 'POST', body });
export const updateMedida = (id, body) => api(`/api/v1/medidas-neumatico/${id}`, { method: 'PUT', body });
export const changeMedidaActivo = (id, activo) => api(`/api/v1/medidas-neumatico/${id}/estado`, { method: 'PATCH', body: { activo } });

export const listEstadosNeumatico = (params) => api(`/api/v1/estados-neumatico${query(params)}`);

export const listNeumaticos = (params) => api(`/api/v1/neumaticos${query(params)}`);
export const getNeumatico = (id) => api(`/api/v1/neumaticos/${id}`);
export const createNeumatico = (body) => api('/api/v1/neumaticos', { method: 'POST', body });
export const updateNeumatico = (id, body) => api(`/api/v1/neumaticos/${id}`, { method: 'PUT', body });
export const deleteNeumatico = (id) => api(`/api/v1/neumaticos/${id}`, { method: 'DELETE' });
export const listHistorialEstados = (id) => api(`/api/v1/neumaticos/${id}/historial-estados`);
export const listVidas = (id) => api(`/api/v1/neumaticos/${id}/vidas`);

export async function asPage(result) {
  if (!result.ok) {
    return { ok: false, rows: [], total: 0, message: result.payload?.err?.message || 'No se pudo consultar.' };
  }
  return { ok: true, rows: result.payload?.data ?? [], total: result.payload?.total ?? 0 };
}
