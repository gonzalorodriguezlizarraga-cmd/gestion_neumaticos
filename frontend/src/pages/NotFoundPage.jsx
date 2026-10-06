import { Link } from 'react-router-dom';

export function NotFoundPage() {
  return (
    <div className="screen-center">
      <div className="status-card">
        <p className="eyebrow">404</p>
        <h1>Página no encontrada</h1>
        <p>La ruta solicitada no existe en este bloque.</p>
        <Link className="button button-primary" to="/dashboard">Ir al panel</Link>
      </div>
    </div>
  );
}
