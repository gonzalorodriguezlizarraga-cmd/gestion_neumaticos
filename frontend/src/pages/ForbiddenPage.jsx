import { Link } from 'react-router-dom';

export function ForbiddenPage() {
  return (
    <div className="screen-center">
      <div className="status-card">
        <p className="eyebrow">403</p>
        <h1>Acceso denegado</h1>
        <p>No tiene permiso para ver este recurso.</p>
        <Link className="button button-primary" to="/login">Volver al ingreso</Link>
      </div>
    </div>
  );
}
