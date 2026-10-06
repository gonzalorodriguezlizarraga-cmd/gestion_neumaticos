export function hasRole(user, ...roles) {
  const granted = user?.roles ?? [];
  return roles.some((role) => granted.includes(role));
}

export function isAdmin(user) {
  return hasRole(user, 'ADMIN_GENERAL');
}

export function canOperateCliente(user) {
  return hasRole(user, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS');
}

export function canManageResponsables(user) {
  return isAdmin(user);
}

export function canManageUnidades(user) {
  return canOperateCliente(user);
}

export function canManageConfiguraciones(user) {
  return canOperateCliente(user);
}

export function canManageTipos(user) {
  return isAdmin(user);
}

export function canManageNeumaticos(user) {
  return canOperateCliente(user);
}

export function canReadComercial(user) {
  return hasRole(user, 'ADMIN_GENERAL', 'VENDEDOR', 'GESTOR_NEUMATICOS');
}

export function canWriteComercial(user) {
  return hasRole(user, 'ADMIN_GENERAL', 'VENDEDOR');
}

export function isPortal(user) {
  return hasRole(user, 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA')
    && !hasRole(user, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS');
}

export function isConsulta(user) {
  return hasRole(user, 'CONSULTA_EJECUTIVA') && !hasRole(user, 'ADMIN_CLIENTE', 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS');
}

export function canSeeReportes(user) {
  return hasRole(user, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'TECNICO_INSPECCION', 'ADMIN_CLIENTE', 'CONSULTA_EJECUTIVA');
}

export function canInspect(user) {
  return hasRole(user, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS', 'TECNICO_INSPECCION');
}

export function canEditInspeccion(user, inspeccion) {
  if (!inspeccion || inspeccion.estado !== 'BORRADOR') {
    return false;
  }
  if (hasRole(user, 'ADMIN_GENERAL', 'GESTOR_NEUMATICOS')) {
    return true;
  }
  return hasRole(user, 'TECNICO_INSPECCION') && Number(inspeccion.tecnico?.id) === Number(user?.id);
}
