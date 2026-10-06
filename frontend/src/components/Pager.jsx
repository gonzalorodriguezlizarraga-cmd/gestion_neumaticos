export function Pager({ page, limit, total, onPage }) {
  const pages = Math.max(1, Math.ceil(total / limit));
  return (
    <div className="pager">
      <span>{total} resultado{total === 1 ? '' : 's'}</span>
      <div>
        <button type="button" className="button button-quiet" disabled={page <= 1} onClick={() => onPage(page - 1)}>
          Anterior
        </button>
        <span>Página {page} de {pages}</span>
        <button type="button" className="button button-quiet" disabled={page >= pages} onClick={() => onPage(page + 1)}>
          Siguiente
        </button>
      </div>
    </div>
  );
}
