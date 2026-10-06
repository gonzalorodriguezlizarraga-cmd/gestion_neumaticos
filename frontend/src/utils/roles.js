const LABELS = {
  ADMIN_GENERAL: 'Administrador general',
  GESTOR_NEUMATICOS: 'Gestor de neumáticos',
  TECNICO_INSPECCION: 'Técnico de inspección',
  VENDEDOR: 'Vendedor',
  ADMIN_CLIENTE: 'Administrador cliente',
  CONSULTA_EJECUTIVA: 'Consulta ejecutiva',
};

export function roleLabel(code) {
  return LABELS[code] || code;
}
