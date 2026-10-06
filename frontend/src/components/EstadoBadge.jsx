const LABELS = {
  POTENCIAL: 'Potencial',
  ACTIVO: 'Activo',
  INACTIVO: 'Inactivo',
  OPERATIVA: 'Operativa',
  INACTIVA: 'Inactiva',
  BAJA: 'Baja',
  DISPONIBLE: 'Disponible',
  MONTADO: 'Montado',
  EN_MANTENIMIENTO: 'En mantenimiento',
  EN_REENCAUCHE: 'En reencauche',
  DESCARTADO: 'Descartado',
  BORRADOR: 'Borrador',
  FINALIZADA: 'Finalizada',
  ANULADA: 'Anulada',
  SOLICITADO: 'Solicitado',
  ENVIADO: 'Enviado',
  EN_PROCESO: 'En proceso',
  FINALIZADO: 'Finalizado',
  CANCELADO: 'Cancelado',
  NORMAL: 'Normal',
  ATENCION: 'Atención',
  CRITICO: 'Crítico',
};

export function EstadoBadge({ estado }) {
  const key = String(estado || '').toUpperCase();
  return <span className={`badge badge-${key.toLowerCase()}`}>{LABELS[key] || estado}</span>;
}
