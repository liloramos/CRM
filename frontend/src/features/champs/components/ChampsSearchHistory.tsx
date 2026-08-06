import { ChevronLeft, ChevronRight, Eye, RefreshCw } from 'lucide-react'
import type { ChampsSearch, PaginationMeta } from '../services/champs.service'
import { ChampsStatusBadge } from './ChampsStatusBadge'

type ChampsSearchHistoryProps = {
  activeSearchId: number | null
  error: string | null
  isLoading: boolean
  meta: PaginationMeta | null
  onOpen: (searchId: number) => void
  onPageChange: (page: number) => void
  onRetry: () => void
  searches: ChampsSearch[]
}

export function ChampsSearchHistory({
  activeSearchId,
  error,
  isLoading,
  meta,
  onOpen,
  onPageChange,
  onRetry,
  searches,
}: ChampsSearchHistoryProps) {
  return (
    <section className="champs-panel champs-history" aria-labelledby="champs-history-title">
      <div className="champs-panel__header">
        <div>
          <span className="champs-section-kicker">HISTÓRICO</span>
          <h2 id="champs-history-title">Garimpagens recentes</h2>
          <p>Consultas persistidas para revisão e comparação.</p>
        </div>
        <button
          aria-label="Atualizar histórico"
          className="champs-icon-button"
          disabled={isLoading}
          onClick={onRetry}
          title="Atualizar histórico"
          type="button"
        >
          <RefreshCw aria-hidden="true" className={isLoading ? 'champs-spin' : undefined} size={18} />
        </button>
      </div>

      {error ? (
        <div className="champs-inline-error" role="alert">
          <span>{error}</span>
          <button className="champs-button champs-button--secondary" onClick={onRetry} type="button">
            Tentar novamente
          </button>
        </div>
      ) : null}

      {isLoading && searches.length === 0 ? (
        <div className="champs-loading-state" role="status">
          <RefreshCw aria-hidden="true" className="champs-spin" size={20} />
          Carregando histórico...
        </div>
      ) : null}

      {!isLoading && !error && searches.length === 0 ? (
        <div className="champs-empty-state">
          <strong>Nenhuma garimpagem realizada.</strong>
          <span>Preencha o formulário para criar seu primeiro histórico.</span>
        </div>
      ) : null}

      {searches.length > 0 ? (
        <div className="champs-table-wrap">
          <table className="champs-history-table">
            <thead>
              <tr>
                <th>Busca</th>
                <th>Status</th>
                <th>Data</th>
                <th>Resultados</th>
                <th>Corte</th>
                <th aria-label="Ações" />
              </tr>
            </thead>
            <tbody>
              {searches.map((search) => (
                <tr
                  aria-current={search.id === activeSearchId ? 'true' : undefined}
                  className={search.id === activeSearchId ? 'is-active' : undefined}
                  key={search.id}
                >
                  <td data-label="Busca">
                    <strong>{search.name}</strong>
                    <small>{search.niche} • {search.city}/{search.state}</small>
                  </td>
                  <td data-label="Status"><ChampsStatusBadge status={search.status} /></td>
                  <td data-label="Data">{formatDate(search.createdAt)}</td>
                  <td data-label="Resultados">
                    <strong>{search.totalDiscovered} encontrados</strong>
                    <small>{search.totalSaved} salvos • {search.totalQualified} qualificados</small>
                  </td>
                  <td data-label="Corte">{search.minimumScore}+</td>
                  <td className="champs-history-table__action" data-label="Ação">
                    <button
                      className="champs-table-action"
                      onClick={() => onOpen(search.id)}
                      type="button"
                    >
                      <Eye aria-hidden="true" size={16} />
                      Abrir resultados
                    </button>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      ) : null}

      {meta && meta.lastPage > 1 ? (
        <div className="champs-pagination" aria-label="Paginação do histórico">
          <button
            aria-label="Página anterior"
            className="champs-icon-button"
            disabled={isLoading || meta.currentPage <= 1}
            onClick={() => onPageChange(meta.currentPage - 1)}
            type="button"
          >
            <ChevronLeft aria-hidden="true" size={18} />
          </button>
          <span>Página {meta.currentPage} de {meta.lastPage}</span>
          <button
            aria-label="Próxima página"
            className="champs-icon-button"
            disabled={isLoading || meta.currentPage >= meta.lastPage}
            onClick={() => onPageChange(meta.currentPage + 1)}
            type="button"
          >
            <ChevronRight aria-hidden="true" size={18} />
          </button>
        </div>
      ) : null}
    </section>
  )
}

function formatDate(value: string | null): string {
  if (!value) {
    return '—'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return '—'
  }

  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(date)
}
