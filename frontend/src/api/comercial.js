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

export const listEstadosOportunidad = () => api('/api/v1/estados-oportunidad');
export const listClientesComerciales = (params) => api(`/api/v1/comercial/clientes${query(params)}`);
export const listResponsablesComerciales = (clienteId) => api(`/api/v1/comercial/clientes/${clienteId}/responsables`);
export const getResumenComercial = (clienteId) => api(`/api/v1/comercial/clientes/${clienteId}/resumen`);

export const listOportunidades = (params) => api(`/api/v1/oportunidades${query(params)}`);
export const getOportunidad = (id) => api(`/api/v1/oportunidades/${id}`);
export const createOportunidad = (body) => api('/api/v1/oportunidades', { method: 'POST', body });
export const updateOportunidad = (id, body) => api(`/api/v1/oportunidades/${id}`, { method: 'PUT', body });
export const crearOportunidadDesdeAlerta = (id, body) => api(`/api/v1/alertas/${id}/crear-oportunidad`, { method: 'POST', body });
export const iniciarSeguimientoOportunidad = (id) => api(`/api/v1/oportunidades/${id}/iniciar-seguimiento`, { method: 'POST', body: {} });
export const marcarCotizadaOportunidad = (id) => api(`/api/v1/oportunidades/${id}/marcar-cotizada`, { method: 'POST', body: {} });
export const ganarOportunidad = (id, body) => api(`/api/v1/oportunidades/${id}/ganar`, { method: 'POST', body });
export const perderOportunidad = (id, body) => api(`/api/v1/oportunidades/${id}/perder`, { method: 'POST', body });
export const cancelarOportunidad = (id, body) => api(`/api/v1/oportunidades/${id}/cancelar`, { method: 'POST', body });

export const listSeguimientos = (params) => api(`/api/v1/seguimientos-comerciales${query(params)}`);
export const createSeguimiento = (body) => api('/api/v1/seguimientos-comerciales', { method: 'POST', body });

export const listCotizaciones = (params) => api(`/api/v1/cotizaciones${query(params)}`);
export const getCotizacion = (id) => api(`/api/v1/cotizaciones/${id}`);
export const createCotizacion = (body) => api('/api/v1/cotizaciones', { method: 'POST', body });
export const updateCotizacion = (id, body) => api(`/api/v1/cotizaciones/${id}`, { method: 'PUT', body });
export const enviarCotizacion = (id) => api(`/api/v1/cotizaciones/${id}/enviar`, { method: 'POST', body: {} });
export const aceptarCotizacion = (id) => api(`/api/v1/cotizaciones/${id}/aceptar`, { method: 'POST', body: {} });
export const rechazarCotizacion = (id) => api(`/api/v1/cotizaciones/${id}/rechazar`, { method: 'POST', body: {} });
export const anularCotizacion = (id) => api(`/api/v1/cotizaciones/${id}/anular`, { method: 'POST', body: {} });
