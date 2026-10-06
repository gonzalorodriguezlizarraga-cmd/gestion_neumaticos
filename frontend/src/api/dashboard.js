import { api } from './client';

function query(params = {}) {
  const search = new URLSearchParams();
  Object.entries(params).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') search.set(key, String(value));
  });
  const text = search.toString();
  return text ? `?${text}` : '';
}

export const dashboardOperador = (params) => api(`/api/v1/dashboard/operador${query(params)}`);
export const dashboardCliente = (params) => api(`/api/v1/dashboard/cliente${query(params)}`);
export const dashboardComercial = () => api('/api/v1/dashboard/comercial');
