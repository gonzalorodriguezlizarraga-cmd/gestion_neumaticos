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

export const listTiposAlerta = () => api('/api/v1/tipos-alerta');
export const listEstadosAlerta = () => api('/api/v1/estados-alerta');
export const listAlertas = (params) => api(`/api/v1/alertas${query(params)}`);
export const getAlerta = (id) => api(`/api/v1/alertas/${id}`);
export const createAlerta = (body) => api('/api/v1/alertas', { method: 'POST', body });
export const tomarAtencionAlerta = (id, body) => api(`/api/v1/alertas/${id}/tomar-atencion`, { method: 'POST', body });
export const atenderAlerta = (id, body) => api(`/api/v1/alertas/${id}/atender`, { method: 'POST', body });
export const descartarAlerta = (id, body) => api(`/api/v1/alertas/${id}/descartar`, { method: 'POST', body });
export const indicadoresNeumatico = (id) => api(`/api/v1/neumaticos/${id}/indicadores`);
export const indicadoresOperativos = (params) => api(`/api/v1/indicadores/operativos${query(params)}`);
