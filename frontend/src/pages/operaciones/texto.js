export const MENSAJE_CONCURRENCIA = 'La posición o el neumático cambió mientras realizabas la operación. Actualiza la información e inténtalo nuevamente.';

export function mensajeOperacion(result) {
  if (result?.status === 409) {
    return MENSAJE_CONCURRENCIA;
  }
  return result?.payload?.err?.message || 'No se pudo completar la operación.';
}

export function fechaLocal() {
  const date = new Date();
  const pad = (value) => String(value).padStart(2, '0');
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
