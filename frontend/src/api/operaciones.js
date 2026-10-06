import { api } from './client';

export const montarNeumatico = (body) => api('/api/v1/montajes', { method: 'POST', body });
export const desmontarNeumatico = (id, body) => api(`/api/v1/montajes/${id}/desmontar`, { method: 'POST', body });
export const rotarNeumaticos = (body) => api('/api/v1/rotaciones', { method: 'POST', body });
export const transferirNeumatico = (body) => api('/api/v1/transferencias', { method: 'POST', body });
export const listMontajesActivos = (unidadId) => api(`/api/v1/unidades/${unidadId}/montajes-activos`);
export const listMontajesNeumatico = (id) => api(`/api/v1/neumaticos/${id}/montajes`);
export const listMovimientosNeumatico = (id) => api(`/api/v1/neumaticos/${id}/movimientos`);
export const listMovimientosUnidad = (id) => api(`/api/v1/unidades/${id}/movimientos`);
