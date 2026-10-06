import { api, apiForm } from './client';

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

export const listTiposMantenimiento = () => api('/api/v1/tipos-mantenimiento');
export const listEstadosMantenimiento = () => api('/api/v1/estados-mantenimiento');
export const listMotivosDescarte = () => api('/api/v1/motivos-descarte');
export const listMantenimientos = (params) => api(`/api/v1/mantenimientos${query(params)}`);
export const getMantenimiento = (id) => api(`/api/v1/mantenimientos/${id}`);
export const createMantenimiento = (body) => api('/api/v1/mantenimientos', { method: 'POST', body });
export const enviarMantenimiento = (id, body) => api(`/api/v1/mantenimientos/${id}/enviar`, { method: 'POST', body });
export const iniciarMantenimiento = (id, body) => api(`/api/v1/mantenimientos/${id}/iniciar`, { method: 'POST', body });
export const finalizarMantenimiento = (id, body) => api(`/api/v1/mantenimientos/${id}/finalizar`, { method: 'POST', body });
export const cancelarMantenimiento = (id, body) => api(`/api/v1/mantenimientos/${id}/cancelar`, { method: 'POST', body });
export const listMantenimientosNeumatico = (id, params) => api(`/api/v1/neumaticos/${id}/mantenimientos${query(params)}`);
export const descartarNeumatico = (id, body) => api(`/api/v1/neumaticos/${id}/descartar`, { method: 'POST', body });
export const getDescarte = (id) => api(`/api/v1/neumaticos/${id}/descarte`);
export const subirArchivoMantenimiento = (id, file, tipo) => apiForm(`/api/v1/mantenimientos/${id}/archivos?tipo_archivo=${tipo}`, file);
