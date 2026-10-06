export function lectura(valor) {
  if (!valor) return '—';
  return String(valor).replace('T', ' ').slice(0, 16);
}

export function resumenTexto(resumen) {
  if (!resumen || resumen.posiciones === 0) return 'Sin mediciones';
  return `${resumen.posiciones} posiciones · ${resumen.criticos} críticos · ${resumen.atencion} atención`;
}

export function ahoraLocal() {
  const fecha = new Date();
  const parte = (valor) => String(valor).padStart(2, '0');
  return `${fecha.getFullYear()}-${parte(fecha.getMonth() + 1)}-${parte(fecha.getDate())}T${parte(fecha.getHours())}:${parte(fecha.getMinutes())}`;
}

export function fechaApi(valor) {
  if (!valor) return '';
  const normal = valor.replace('T', ' ');
  return normal.length === 16 ? `${normal}:00` : normal;
}

export function fechaLocal(valor) {
  if (!valor) return '';
  return String(valor).replace(' ', 'T').slice(0, 16);
}

export function ladoTexto(lado) {
  return { IZQUIERDO: 'Izquierdo', DERECHO: 'Derecho', CENTRO: 'Centro' }[lado] || lado || '—';
}

export function ubicacionTexto(ubicacion) {
  return { INTERIOR: 'Interior', EXTERIOR: 'Exterior', SIMPLE: 'Simple', CENTRAL: 'Central' }[ubicacion] || ubicacion || '—';
}

export function numeroONull(valor) {
  if (valor === undefined || valor === null || String(valor).trim() === '') return null;
  return String(valor).trim();
}

export function diferenciaCanales(detalle) {
  const valores = [detalle.profundidad_interior_mm, detalle.profundidad_centro_mm, detalle.profundidad_exterior_mm]
    .filter((valor) => valor !== null && valor !== undefined && valor !== '')
    .map(Number);
  if (valores.length < 3 || valores.some((valor) => Number.isNaN(valor))) return null;
  return (Math.max(...valores) - Math.min(...valores)).toFixed(2);
}
