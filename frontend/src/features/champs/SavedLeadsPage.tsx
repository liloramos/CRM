import { Download, Heart, RefreshCw } from 'lucide-react'
import { useCallback, useEffect, useState } from 'react'
import './ChampsPage.css'
import './SavedLeadsPage.css'
import { SavedLeadDrawer } from './components/SavedLeadDrawer'
import { SavedLeadsFilters } from './components/SavedLeadsFilters'
import { SavedLeadsStats } from './components/SavedLeadsStats'
import { SavedLeadsTable } from './components/SavedLeadsTable'
import type { ChampsLead } from './services/champs.service'
import {
  archiveSavedLead,
  createLeadActivity,
  listLeadActivities,
  listSavedLeads,
  restoreSavedLead,
  updateLeadAssignment,
  updateLeadFavorite,
  updateLeadFollowUp,
  updateLeadPipeline,
  type ChampsLeadActivity,
  type CreateLeadActivityPayload,
  type SavedLeadsFilters as SavedLeadsFiltersState,
  type SavedLeadsSummary,
} from './services/saved-leads.service'
import { dateStamp } from './utils/champs-csv'
import { champsErrorMessage } from './services/champs.service'
import { buildSavedLeadsExportCsv } from './utils/saved-leads'

const EMPTY_SUMMARY: SavedLeadsSummary = {
  saved: 0,
  interested: 0,
  contacted: 0,
  meetings: 0,
  overdue: 0,
}

const DEFAULT_FILTERS: SavedLeadsFiltersState = {
  archived: 'active',
  direction: 'desc',
  orderBy: 'updated_at',
  page: 1,
  perPage: 50,
}

export function SavedLeadsPage() {
  const [filters, setFilters] = useState<SavedLeadsFiltersState>(DEFAULT_FILTERS)
  const [leads, setLeads] = useState<ChampsLead[]>([])
  const [summary, setSummary] = useState<SavedLeadsSummary>(EMPTY_SUMMARY)
  const [assignees, setAssignees] = useState<Array<{ id: number; name: string }>>([])
  const [meta, setMeta] = useState<{ currentPage: number; lastPage: number; total: number } | null>(null)
  const [selectedLead, setSelectedLead] = useState<ChampsLead | null>(null)
  const [activities, setActivities] = useState<ChampsLeadActivity[]>([])
  const [isLoading, setIsLoading] = useState(true)
  const [isLoadingActivities, setIsLoadingActivities] = useState(false)
  const [isMutating, setIsMutating] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [feedback, setFeedback] = useState('')

  const loadLeads = useCallback(async () => {
    setIsLoading(true)
    setError(null)

    try {
      const response = await listSavedLeads(filters)
      setLeads(response.data)
      setSummary(response.summary)
      setAssignees(response.assignees)
      setMeta({
        currentPage: response.meta.currentPage,
        lastPage: response.meta.lastPage,
        total: response.meta.total,
      })
    } catch (loadError) {
      setError(champsErrorMessage(loadError))
    } finally {
      setIsLoading(false)
    }
  }, [filters])

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      void loadLeads()
    }, 0)

    return () => window.clearTimeout(timeout)
  }, [loadLeads])

  async function openLead(lead: ChampsLead) {
    setSelectedLead(lead)
    setActivities([])
    setIsLoadingActivities(true)

    try {
      const response = await listLeadActivities(lead.id)
      setActivities(response.data)
    } catch (activityError) {
      setFeedback(champsErrorMessage(activityError))
    } finally {
      setIsLoadingActivities(false)
    }
  }

  async function mutateLead(
    action: () => Promise<ChampsLead>,
    successMessage: string,
  ) {
    setIsMutating(true)

    try {
      const updated = await action()
      replaceLead(updated)
      setFeedback(successMessage)
      await loadLeads()
    } catch (mutationError) {
      setFeedback(champsErrorMessage(mutationError))
    } finally {
      setIsMutating(false)
    }
  }

  async function addActivity(payload: CreateLeadActivityPayload) {
    if (!selectedLead) {
      return
    }

    setIsMutating(true)

    try {
      const activity = await createLeadActivity(selectedLead.id, payload)
      setActivities((current) => [activity, ...current])
      setSelectedLead((current) => {
        if (!current) {
          return current
        }

        return {
          ...current,
          commercialNotes: payload.type === 'note' ? payload.description?.trim() ?? current.commercialNotes : current.commercialNotes,
          lastContactedAt: payload.type === 'contacted' ? payload.occurredAt ?? new Date().toISOString() : current.lastContactedAt,
          activitiesCount: (current.activitiesCount ?? activities.length) + 1,
        }
      })
      setFeedback(payload.type === 'contacted' ? 'Contato registrado no histórico.' : 'Nota adicionada ao histórico.')
      await loadLeads()
    } catch (activityError) {
      setFeedback(champsErrorMessage(activityError))
    } finally {
      setIsMutating(false)
    }
  }

  function replaceLead(updated: ChampsLead) {
    setLeads((current) => current.map((lead) => lead.id === updated.id ? updated : lead))
    setSelectedLead((current) => current?.id === updated.id ? updated : current)
  }

  function updateFilters(next: SavedLeadsFiltersState) {
    setFilters(next)
  }

  function clearFilters() {
    setFilters(DEFAULT_FILTERS)
  }

  function downloadCsv() {
    if (leads.length === 0) {
      setFeedback('Não há leads no filtro atual para exportar.')
      return
    }

    const blob = new Blob([buildSavedLeadsExportCsv(leads)], { type: 'text/csv;charset=utf-8' })
    const url = URL.createObjectURL(blob)
    const anchor = document.createElement('a')

    anchor.href = url
    anchor.download = `champs-leads-salvos-${dateStamp()}.csv`
    anchor.click()
    URL.revokeObjectURL(url)
    setFeedback(`${leads.length} lead(s) exportado(s).`)
    void Promise.allSettled(
      leads.map((lead) => createLeadActivity(lead.id, { type: 'exported' })),
    )
  }

  return (
    <main className="champs-page saved-leads-page">
      <section className="champs-hero saved-leads-hero">
        <div>
          <span className="champs-eyebrow">MQ • OPERAÇÃO COMERCIAL</span>
          <h1>Leads salvos</h1>
          <p>Organize prioridade, responsável e próximos passos para transformar oportunidades em reuniões.</p>
        </div>
        <div className="champs-actions">
          <button className="champs-button champs-button--secondary" disabled={isLoading} onClick={() => void loadLeads()} type="button">
            <RefreshCw aria-hidden="true" className={isLoading ? 'champs-spin' : undefined} size={17} />
            Atualizar
          </button>
          <button className="champs-button champs-button--primary" disabled={leads.length === 0} onClick={downloadCsv} type="button">
            <Download aria-hidden="true" size={17} />
            Exportar CSV
          </button>
        </div>
      </section>

      {feedback ? (
        <div className="champs-feedback" role="status">
          {feedback}
          <button aria-label="Fechar mensagem" onClick={() => setFeedback('')} type="button">×</button>
        </div>
      ) : null}

      <SavedLeadsStats summary={summary} />

      <section className="champs-panel saved-leads-panel" aria-labelledby="saved-leads-title">
        <div className="champs-panel__header saved-leads-panel__header">
          <div>
            <span className="champs-section-kicker">CENTRAL OPERACIONAL</span>
            <h2 id="saved-leads-title">Acompanhamento comercial</h2>
            <p>{meta?.total ?? 0} lead(s) no recorte atual.</p>
          </div>
          <div className="saved-leads-panel__hint"><Heart aria-hidden="true" size={16} /> Favoritos, pipeline e acompanhamentos</div>
        </div>

        <SavedLeadsFilters assignees={assignees} filters={filters} onChange={updateFilters} onClear={clearFilters} />

        {error ? (
          <div className="champs-inline-error saved-leads-error" role="alert">
            <span>{error}</span>
            <button className="champs-button champs-button--secondary" onClick={() => void loadLeads()} type="button">
              Tentar novamente
            </button>
          </div>
        ) : null}

        {isLoading && !error ? (
          <div className="champs-loading-state" role="status">
            <RefreshCw aria-hidden="true" className="champs-spin" size={20} />
            Carregando central operacional...
          </div>
        ) : null}

        {!isLoading && !error ? (
          <SavedLeadsTable
            isMutating={isMutating}
            leads={leads}
            onArchive={(lead) => void mutateLead(() => archiveSavedLead(lead.id), 'Lead arquivado. Os dados e atividades foram preservados.')}
            onOpen={(lead) => void openLead(lead)}
            onRestore={(lead) => void mutateLead(() => restoreSavedLead(lead.id), 'Lead restaurado para a central operacional.')}
            onToggleFavorite={(lead) => void mutateLead(
              () => updateLeadFavorite(lead.id, !lead.isFavorite),
              lead.isFavorite ? 'Lead removido dos favoritos.' : 'Lead adicionado aos favoritos.',
            )}
          />
        ) : null}

        {meta && meta.lastPage > 1 ? (
          <nav aria-label="Paginação dos leads salvos" className="saved-leads-pagination">
            <button
              className="champs-button champs-button--secondary"
              disabled={isLoading || meta.currentPage <= 1}
              onClick={() => setFilters((current) => ({ ...current, page: meta.currentPage - 1 }))}
              type="button"
            >
              Anterior
            </button>
            <span>Página {meta.currentPage} de {meta.lastPage}</span>
            <button
              className="champs-button champs-button--secondary"
              disabled={isLoading || meta.currentPage >= meta.lastPage}
              onClick={() => setFilters((current) => ({ ...current, page: meta.currentPage + 1 }))}
              type="button"
            >
              Próxima
            </button>
          </nav>
        ) : null}
      </section>

      {selectedLead ? (
        <SavedLeadDrawer
          key={selectedLead.id}
          activities={activities}
          assignees={assignees}
          isBusy={isMutating}
          isLoadingActivities={isLoadingActivities}
          lead={selectedLead}
          onArchive={() => void mutateLead(() => archiveSavedLead(selectedLead.id), 'Lead arquivado. Os dados e atividades foram preservados.')}
          onAssign={(userId) => void mutateLead(() => updateLeadAssignment(selectedLead.id, userId), 'Responsável atualizado.')}
          onClose={() => setSelectedLead(null)}
          onContact={() => void addActivity({ type: 'contacted' })}
          onFollowUp={(value) => void mutateLead(() => updateLeadFollowUp(selectedLead.id, value), value ? 'Próximo acompanhamento atualizado.' : 'Acompanhamento removido.')}
          onNote={(description) => void addActivity({ type: 'note', description })}
          onPipeline={(payload) => void mutateLead(
            () => updateLeadPipeline(selectedLead.id, payload),
            'Pipeline comercial atualizado.',
          )}
          onRestore={() => void mutateLead(() => restoreSavedLead(selectedLead.id), 'Lead restaurado para a central operacional.')}
          onToggleFavorite={() => void mutateLead(
            () => updateLeadFavorite(selectedLead.id, !selectedLead.isFavorite),
            selectedLead.isFavorite ? 'Lead removido dos favoritos.' : 'Lead adicionado aos favoritos.',
          )}
        />
      ) : null}
    </main>
  )
}
