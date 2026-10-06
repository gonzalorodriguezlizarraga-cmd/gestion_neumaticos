const LABELS = {
  POTENCIAL: 'Potencial',
  ACTIVO: 'Activo',
  INACTIVO: 'Inactivo',
};

export function EstadoBadge({ estado }) {
  const key = String(estado || '').toUpperCase();
  return <span className={`badge badge-${key.toLowerCase()}`}>{LABELS[key] || estado}</span>;
}
