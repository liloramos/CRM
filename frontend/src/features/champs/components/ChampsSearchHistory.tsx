import { Archive, ChevronLeft, ChevronRight, Eye, RefreshCw, RotateCcw, Trash2 } from 'lucide-react'
import type { ChampsSearch, PaginationMeta } from '../services/champs.service'
import { ChampsStatusBadge } from './ChampsStatusBadge'

type ChampsSearchHistoryProps = {
  activeSearchId: number | null
  archiveFilter: 'active' | 'only'
  error: string | null
  isLoading: boolean
  isMutating: boolean
  meta: PaginationMeta | null
  onArchive: (search: ChampsSearch) => void
  onArchiveAll: () => void
  onArchiveFilterChange: (filter: 'active' | 'only') => void
  onOpen: (searchId: number) => void
  onPageChange: (page: number) => void
  onRetry: () => void
  onRestore: (search: ChampsSearch) => void
  searches: ChampsSearch[]
}

export function ChampsSearchHistory({
  activeSearchId,
  archiveFilter,
  error,
  isLoading,
  isMutating,
  meta,
  onArchive,
  onArchiveAll,
  onArchiveFilterChange,
  onOpen,
  onPageChange,
  onRetry,
  onRestore,
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
        <div className="champs-history__toolbar">
          <div aria-label="Visualização do histórico" className="champs-history-filter" role="group">
            <button
              aria-pressed={archiveFilter === 'active'}
              onClick={() => onArchiveFilterChange('active')}
              type="button"
            >
              Ativas
            </button>
            <button
              aria-pressed={archiveFilter === 'only'}
              onClick={() => onArchiveFilterChange('only')}
              type="button"
            >
              Arquivadas
            </button>
          </div>
          {archiveFilter === 'active' ? (
            <button
              className="champs-button champs-button--danger champs-history-clear"
              disabled={isLoading || isMutating || (meta?.total ?? 0) === 0}
              onClick={onArchiveAll}
              type="button"
            >
              <Trash2 aria-hidden="true" size={16} />
              Limpar histórico
            </button>
          ) : null}
          <button
            aria-label="Atualizar histórico"
            className="champs-icon-button"
            disabled={isLoading || isMutating}
            onClick={onRetry}
            title="Atualizar histórico"
            type="button"
          >
            <RefreshCw aria-hidden="true" className={isLoading ? 'champs-spin' : undefined} size={18} />
          </button>
        </div>
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
          <strong>{archiveFilter === 'only' ? 'Nenhuma garimpagem arquivada.' : 'Nenhuma garimpagem ativa.'}</strong>
          <span>
            {archiveFilter === 'only'
              ? 'Pesquisas arquivadas poderão ser restauradas por aqui.'
              : 'Preencha o formulário para criar uma nova garimpagem.'}
          </span>
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
                  <td data-label="Data">
                    {formatDate(search.createdAt)}
                    {search.archivedAt ? <small>Arquivada em {formatDate(search.archivedAt)}</small> : null}
                  </td>
                  <td data-label="Resultados">
                    <strong>{search.totalDiscovered} encontrados</strong>
                    <small>{search.totalSaved} salvos • {search.totalQualified} qualificados</small>
                    <small>{search.totalScanned} analisados • {search.totalSkippedSeen} repetidos ocultos</small>
                  </td>
                  <td data-label="Corte">{search.minimumScore}+</td>
                  <td className="champs-history-table__action" data-label="Ação">
                    <div className="champs-history-actions">
                      <button
                        className="champs-table-action"
                        onClick={() => onOpen(search.id)}
                        type="button"
                      >
                        <Eye aria-hidden="true" size={16} />
                        Abrir resultados
                      </button>
                      {archiveFilter === 'active' ? (
                        <button
                          aria-label={`Arquivar ${search.name}`}
                          className="champs-table-action"
                          disabled={isMutating}
                          onClick={() => onArchive(search)}
                          type="button"
                        >
                          <Archive aria-hidden="true" size={15} />
                          Arquivar
                        </button>
                      ) : (
                        <button
                          aria-label={`Restaurar ${search.name}`}
                          className="champs-table-action"
                          disabled={isMutating}
                          onClick={() => onRestore(search)}
                          type="button"
                        >
                          <RotateCcw aria-hidden="true" size={15} />
                          Restaurar
                        </button>
                      )}
                    </div>
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
