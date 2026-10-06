export function fechaVisible(valor) {
  if (!valor) return '—';
  const texto = String(valor).replace('T', ' ');
  const [fecha, hora] = texto.split(' ');
  const partes = fecha.split('-');
  if (partes.length !== 3) return texto;
  const [anio, mes, dia] = partes;
  return hora ? `${dia}/${mes}/${anio} ${hora.slice(0, 5)}` : `${dia}/${mes}/${anio}`;
}
