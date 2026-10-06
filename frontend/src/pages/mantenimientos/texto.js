export function lectura(valor) {
  if (valor === null || valor === undefined || valor === '') return '—';
  return String(valor).replace('T', ' ');
}

export function costoTexto(costo, moneda) {
  if (costo === null || costo === undefined || costo === '') return '—';
  return `${costo} ${moneda || ''}`.trim();
}

export function aFecha(valor) {
  if (!valor) return '';
  const normal = String(valor).replace('T', ' ');
  return normal.length === 16 ? `${normal}:00` : normal;
}

export function ahoraLocal() {
  const date = new Date();
  const pad = (numero) => String(numero).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function mensaje(result) {
  const details = result?.payload?.err?.details;
  if (details && typeof details === 'object') {
    const first = Object.values(details).flat()[0];
    if (first) return String(first);
  }
  return result?.payload?.err?.message || 'No se pudo completar la operación.';
}
