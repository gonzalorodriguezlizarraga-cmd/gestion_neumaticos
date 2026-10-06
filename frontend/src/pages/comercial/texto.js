export function mensaje(result) {
  const details = result?.payload?.err?.details;
  if (details && typeof details === 'object') {
    const first = Object.values(details).flat()[0];
    if (first) return String(first);
  }
  return result?.payload?.err?.message || 'No se pudo completar la operación.';
}

export function nombre(persona) {
  if (!persona) return '—';
  return [persona.nombres, persona.apellidos].filter(Boolean).join(' ') || '—';
}

export function clienteNombre(cliente) {
  if (!cliente) return '—';
  return cliente.nombre_comercial || cliente.razon_social || '—';
}

export function dinero(valor) {
  if (valor === null || valor === undefined || valor === '') return '—';
  const numero = Number(valor);
  if (!Number.isFinite(numero)) return String(valor);
  return numero.toLocaleString('es-PE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

export function subtotalLinea(cantidad, precio) {
  const c = Number(cantidad);
  const p = Number(precio);
  if (!Number.isFinite(c) || !Number.isFinite(p) || c < 0 || p < 0) return null;
  return Math.round((c * p) * 100) / 100;
}

export function proximoTexto(item) {
  if (!item?.fecha) return 'Sin fecha';
  const etiqueta = { vencido: 'Vencido', hoy: 'Hoy', proximo: 'Próximo' }[item.indicador] || 'Próximo';
  return `${etiqueta}: ${item.fecha}`;
}

export const ORIGENES = [
  ['MANUAL', 'Manual'],
  ['ALERTA', 'Alerta'],
  ['PROYECCION', 'Proyección'],
];

export const TIPOS_SEGUIMIENTO = [
  ['LLAMADA', 'Llamada'],
  ['VISITA', 'Visita'],
  ['REUNION', 'Reunión'],
  ['CORREO', 'Correo'],
  ['OTRO', 'Otro'],
];

export const ESTADOS_COTIZACION = [
  ['BORRADOR', 'Borrador'],
  ['ENVIADA', 'Enviada'],
  ['ACEPTADA', 'Aceptada'],
  ['RECHAZADA', 'Rechazada'],
  ['ANULADA', 'Anulada'],
];
