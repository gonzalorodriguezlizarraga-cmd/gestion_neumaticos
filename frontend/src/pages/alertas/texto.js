export function mensaje(result) {
  const details = result?.payload?.err?.details;
  if (details && typeof details === 'object') {
    const first = Object.values(details).flat()[0];
    if (first) return String(first);
  }
  return result?.payload?.err?.message || 'No se pudo completar la operación.';
}

export function origenTexto(origen) {
  return origen === 'AUTOMATICA' ? 'Automática' : 'Manual';
}

export function medida(valor, unidad) {
  if (valor === null || valor === undefined || valor === '') return 'No disponible';
  return unidad ? `${valor} ${unidad}` : String(valor);
}

export function costosTexto(mapa) {
  const entries = Object.entries(mapa || {});
  if (entries.length === 0) return 'No disponible';
  return entries.map(([moneda, total]) => `${total} ${moneda}`).join(' · ');
}
