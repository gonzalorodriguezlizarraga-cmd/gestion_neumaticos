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

export function listClientes(params) {
  return api(`/api/v1/clientes${query(params)}`);
}

export function getCliente(id) {
  return api(`/api/v1/clientes/${id}`);
}

export function createCliente(body) {
  return api('/api/v1/clientes', { method: 'POST', body });
}

export function updateCliente(id, body) {
  return api(`/api/v1/clientes/${id}`, { method: 'PUT', body });
}

export function changeClienteEstado(id, estado) {
  return api(`/api/v1/clientes/${id}/estado`, { method: 'PATCH', body: { estado } });
}

export function deleteCliente(id) {
  return api(`/api/v1/clientes/${id}`, { method: 'DELETE' });
}

export function listContactos(clienteId, params) {
  return api(`/api/v1/clientes/${clienteId}/contactos${query(params)}`);
}

export function createContacto(clienteId, body) {
  return api(`/api/v1/clientes/${clienteId}/contactos`, { method: 'POST', body });
}

export function updateContacto(clienteId, id, body) {
  return api(`/api/v1/clientes/${clienteId}/contactos/${id}`, { method: 'PUT', body });
}

export function changeContactoActivo(clienteId, id, activo) {
  return api(`/api/v1/clientes/${clienteId}/contactos/${id}/estado`, { method: 'PATCH', body: { activo } });
}

export function listResponsables(clienteId, params) {
  return api(`/api/v1/clientes/${clienteId}/responsables${query(params)}`);
}

export function createResponsable(clienteId, body) {
  return api(`/api/v1/clientes/${clienteId}/responsables`, { method: 'POST', body });
}

export function closeResponsable(clienteId, id) {
  return api(`/api/v1/clientes/${clienteId}/responsables/${id}`, { method: 'DELETE' });
}

export function listSedes(clienteId, params) {
  return api(`/api/v1/clientes/${clienteId}/sedes${query(params)}`);
}

export function createSede(clienteId, body) {
  return api(`/api/v1/clientes/${clienteId}/sedes`, { method: 'POST', body });
}

export function updateSede(clienteId, id, body) {
  return api(`/api/v1/clientes/${clienteId}/sedes/${id}`, { method: 'PUT', body });
}

export function deleteSede(clienteId, id) {
  return api(`/api/v1/clientes/${clienteId}/sedes/${id}`, { method: 'DELETE' });
}

export function listFlotas(clienteId, params) {
  return api(`/api/v1/clientes/${clienteId}/flotas${query(params)}`);
}

export function createFlota(clienteId, body) {
  return api(`/api/v1/clientes/${clienteId}/flotas`, { method: 'POST', body });
}

export function updateFlota(clienteId, id, body) {
  return api(`/api/v1/clientes/${clienteId}/flotas/${id}`, { method: 'PUT', body });
}

export function deleteFlota(clienteId, id) {
  return api(`/api/v1/clientes/${clienteId}/flotas/${id}`, { method: 'DELETE' });
}

export function errorMessage(result, fallback = 'No se pudo completar la operación.') {
  return result?.payload?.err?.message || fallback;
}

export function errorDetails(result) {
  return result?.payload?.err?.details || null;
}
