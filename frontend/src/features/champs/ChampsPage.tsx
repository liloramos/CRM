import {
  Download,
  FileDown,
  FlaskConical,
  RefreshCw,
  Trash2,
  Upload,
} from 'lucide-react'
import { useCallback, useEffect, useMemo, useState, type ChangeEvent } from 'react'
import './ChampsPage.css'
import { ChampsLeadFilters } from './components/ChampsLeadFilters'
import { ChampsLeadTable } from './components/ChampsLeadTable'
import { ChampsSearchForm } from './components/ChampsSearchForm'
import { ChampsSearchHistory } from './components/ChampsSearchHistory'
import { ChampsStats } from './components/ChampsStats'
import { ChampsStatusBadge } from './components/ChampsStatusBadge'
import {
  champsErrorMessage,
  createSearch,
  getSearch,
  listSearches,
  type ChampsSearch,
  type CreateChampsSearchPayload,
  type PaginationMeta,
} from './services/champs.service'
import { buildTemplateCsv, dateStamp, parseCsv } from './utils/champs-csv'
import { mergeLeads, demoLeads, type Lead } from './utils/champs-leads'
import {
  buildSearchResultsExportCsv,
  filterSearchResults,
  legacyLeadToSearchResult,
  type ChampsResultFilters,
} from './utils/champs-results'
import { readChampsPreferences, writeChampsPreferences } from './utils/champs-storage'

type ResultSource = 'api' | 'local'

export function ChampsPage() {
  const [initialPreferences] = useState(readChampsPreferences)
  const [searches, setSearches] = useState<ChampsSearch[]>([])
  const [historyMeta, setHistoryMeta] = useState<PaginationMeta | null>(null)
  const [activeSearch, setActiveSearch] = useState<ChampsSearch | null>(null)
  const [localLeads, setLocalLeads] = useState<Lead[]>([])
  const [resultSource, setResultSource] = useState<ResultSource>('api')
  const [filters, setFilters] = useState<ChampsResultFilters>({
    stateFilter: initialPreferences.stateFilter,
    qualificationFilter: initialPreferences.qualificationFilter,
    minimumScore: initialPreferences.minimumScore,
    query: initialPreferences.query,
    order: initialPreferences.order,
  })
  const [feedback, setFeedback] = useState('')
  const [historyError, setHistoryError] = useState<string | null>(null)
  const [resultsError, setResultsError] = useState<string | null>(null)
  const [isHistoryLoading, setIsHistoryLoading] = useState(true)
  const [isOpeningSearch, setIsOpeningSearch] = useState(false)
  const [isCreatingSearch, setIsCreatingSearch] = useState(false)

  const openSearch = useCallback(async (searchId: number, focusResults = true) => {
    setResultSource('api')
    setIsOpeningSearch(true)
    setResultsError(null)

    try {
      const search = await getSearch(searchId)
      setActiveSearch(search)

      if (focusResults) {
        focusResultsPanel()
      }
    } catch (error) {
      setResultsError(champsErrorMessage(error))
    } finally {
      setIsOpeningSearch(false)
    }
  }, [])

  const loadHistory = useCallback(async (page = 1, openInitialSearch = false) => {
    setIsHistoryLoading(true)
    setHistoryError(null)

    try {
      const response = await listSearches({ page, perPage: 10 })
      setSearches(response.data)
      setHistoryMeta(response.meta)

      if (openInitialSearch && response.data.length > 0) {
        const preferredSearch = response.data.find((search) => search.id === initialPreferences.lastSearchId)
        await openSearch((preferredSearch ?? response.data[0]).id, false)
      }
    } catch (error) {
      setHistoryError(champsErrorMessage(error))
    } finally {
      setIsHistoryLoading(false)
    }
  }, [initialPreferences.lastSearchId, openSearch])

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      void loadHistory(1, true)
    }, 0)

    return () => window.clearTimeout(timeout)
  }, [loadHistory])

  useEffect(() => {
    writeChampsPreferences({
      ...filters,
      lastSearchId: activeSearch?.id ?? initialPreferences.lastSearchId,
    })
  }, [activeSearch?.id, filters, initialPreferences.lastSearchId])

  const localResults = useMemo(
    () => localLeads.map(legacyLeadToSearchResult),
    [localLeads],
  )
  const sourceResults = useMemo(
    () => resultSource === 'local' ? localResults : (activeSearch?.results ?? []),
    [activeSearch?.results, localResults, resultSource],
  )
  const resultContext = useMemo(
    () => resultSource === 'api'
      ? { fallbackCity: activeSearch?.city, fallbackState: activeSearch?.state }
      : {},
    [activeSearch?.city, activeSearch?.state, resultSource],
  )
  const filteredResults = useMemo(
    () => filterSearchResults(sourceResults, filters, resultContext),
    [filters, resultContext, sourceResults],
  )

  async function handleCreateSearch(payload: CreateChampsSearchPayload) {
    if (isCreatingSearch) {
      return
    }

    setIsCreatingSearch(true)
    setFeedback('')
    setResultsError(null)

    try {
      const createdSearch = await createSearch(payload)
      const completeSearch = createdSearch.resultsLoaded
        ? createdSearch
        : await getSearch(createdSearch.id)

      setActiveSearch(completeSearch)
      setResultSource('api')
      setFeedback(
        `${completeSearch.totalDiscovered} lead(s) encontrado(s); ${completeSearch.totalQualified} atingiram o score mínimo.`,
      )
      focusResultsPanel()
      await loadHistory(1)
    } catch (error) {
      setFeedback(champsErrorMessage(error, 'create'))
      await loadHistory(1)
    } finally {
      setIsCreatingSearch(false)
    }
  }

  async function handleCsvImport(event: ChangeEvent<HTMLInputElement>) {
    const file = event.target.files?.[0]
    event.target.value = ''

    if (!file) {
      return
    }

    try {
      const text = await file.text()
      const parsed = parseCsv(text)
      const rejectedMessage = parsed.rejected > 0 ? ` e ${parsed.rejected} linha(s) ignorada(s).` : '.'

      if (parsed.leads.length === 0) {
        setFeedback(`0 lead(s) importado(s)${rejectedMessage} Confira os dados do arquivo.`)
        return
      }

      setLocalLeads((current) => mergeLeads(current, parsed.leads))
      setResultSource('local')
      setFeedback(`${parsed.leads.length} lead(s) importado(s)${rejectedMessage} Dados mantidos somente nesta sessão.`)
      focusResultsPanel()
    } catch {
      setFeedback('Não foi possível ler o CSV. Confira o arquivo e tente novamente.')
    }
  }

  function loadDemo() {
    setLocalLeads((current) => mergeLeads(current, demoLeads()))
    setResultSource('local')
    setFeedback('Dados fictícios carregados somente nesta sessão.')
    focusResultsPanel()
  }

  function clearLocalLeads() {
    if (!window.confirm('Remover os leads importados nesta sessão?')) {
      return
    }

    setLocalLeads([])
    setResultSource('api')
    setFeedback('Dados temporários removidos. O histórico persistente não foi alterado.')
  }

  function exportFilteredResults() {
    if (filteredResults.length === 0) {
      setFeedback('Não há leads no filtro atual para exportar.')
      return
    }

    downloadCsvContent(
      `champs-leads-${dateStamp()}.csv`,
      buildSearchResultsExportCsv(filteredResults, resultContext),
    )
    setFeedback(`${filteredResults.length} lead(s) exportado(s).`)
  }

  function downloadTemplate() {
    downloadCsvContent('modelo-importacao-champs.csv', buildTemplateCsv())
  }

  const hasSelectedSource = resultSource === 'local' ? localLeads.length > 0 : activeSearch !== null
  const activeSearchId = resultSource === 'api' ? activeSearch?.id ?? null : null

  return (
    <main className="champs-page">
      <section className="champs-hero">
        <div>
          <span className="champs-eyebrow">MQ • INTELIGÊNCIA COMERCIAL</span>
          <h1>Qualificação de leads</h1>
          <p>Garimpe oportunidades reais, revise o score e preserve cada consulta no histórico da empresa.</p>
        </div>

        <div className="champs-actions">
          <button className="champs-button champs-button--ghost" onClick={downloadTemplate} type="button">
            <FileDown aria-hidden="true" size={17} />
            Baixar modelo CSV
          </button>

          <button className="champs-button champs-button--secondary" onClick={loadDemo} type="button">
            <FlaskConical aria-hidden="true" size={17} />
            Carregar demonstração
          </button>

          <label className="champs-button champs-button--primary import-csv-button">
            <Upload aria-hidden="true" size={17} />
            Importar CSV
            <input accept=".csv,text/csv" hidden onChange={handleCsvImport} type="file" />
          </label>
        </div>
      </section>

      {feedback ? (
        <div className="champs-feedback" role="status">
          {feedback}
          <button aria-label="Fechar mensagem" onClick={() => setFeedback('')} type="button">×</button>
        </div>
      ) : null}

      <ChampsSearchForm isSubmitting={isCreatingSearch} onSubmit={(payload) => void handleCreateSearch(payload)} />

      <section className="champs-results" id="champs-results" aria-live="polite">
        {isOpeningSearch ? (
          <div className="champs-panel champs-loading-state" role="status">
            <RefreshCw aria-hidden="true" className="champs-spin" size={21} />
            Carregando resultados da garimpagem...
          </div>
        ) : null}

        {!isOpeningSearch && resultsError ? (
          <div className="champs-panel champs-error-state" role="alert">
            <strong>Não foi possível abrir os resultados.</strong>
            <span>{resultsError}</span>
            <button
              className="champs-button champs-button--secondary"
              disabled={!activeSearch && searches.length === 0}
              onClick={() => {
                const retrySearchId = activeSearch?.id ?? searches[0]?.id

                if (retrySearchId) {
                  void openSearch(retrySearchId)
                }
              }}
              type="button"
            >
              <RefreshCw aria-hidden="true" size={17} />
              Tentar novamente
            </button>
          </div>
        ) : null}

        {!isOpeningSearch && !resultsError && hasSelectedSource ? (
          <>
            <ChampsStats
              isLocal={resultSource === 'local'}
              results={sourceResults}
              search={resultSource === 'api' ? activeSearch : null}
            />

            <section className="champs-panel champs-results-panel" aria-labelledby="champs-results-title">
              <div className="champs-panel__header">
                <div>
                  <span className="champs-section-kicker">
                    {resultSource === 'local' ? 'BASE TEMPORÁRIA' : 'RESULTADOS PERSISTIDOS'}
                  </span>
                  <div className="champs-results-title-row">
                    <h2 id="champs-results-title">
                      {resultSource === 'local' ? 'Importação da sessão' : activeSearch?.name}
                    </h2>
                    {resultSource === 'api' && activeSearch ? <ChampsStatusBadge status={activeSearch.status} /> : null}
                    {resultSource === 'local' ? <span className="champs-local-badge">Não persistido</span> : null}
                  </div>
                  <p>
                    {resultSource === 'api' && activeSearch
                      ? `${activeSearch.niche} • ${activeSearch.city}/${activeSearch.state} • corte ${activeSearch.minimumScore}+`
                      : 'Dados importados localmente; o arquivo legado salvo no navegador permanece intocado.'}
                  </p>
                </div>

                <div className="champs-panel__actions">
                  {resultSource === 'api' && activeSearch ? (
                    <button
                      aria-label="Atualizar resultados"
                      className="champs-button champs-button--secondary"
                      onClick={() => void openSearch(activeSearch.id, false)}
                      type="button"
                    >
                      <RefreshCw aria-hidden="true" size={17} />
                      Atualizar
                    </button>
                  ) : null}
                  {resultSource === 'local' ? (
                    <button className="champs-button champs-button--danger" onClick={clearLocalLeads} type="button">
                      <Trash2 aria-hidden="true" size={17} />
                      Limpar sessão
                    </button>
                  ) : null}
                  <button
                    className="champs-button champs-button--primary"
                    disabled={filteredResults.length === 0}
                    onClick={exportFilteredResults}
                    type="button"
                  >
                    <Download aria-hidden="true" size={17} />
                    Exportar CSV
                  </button>
                </div>
              </div>

              {resultSource === 'api' && activeSearch?.status === 'failed' ? (
                <div className="champs-search-failed" role="status">
                  <strong>Esta garimpagem não foi concluída.</strong>
                  <span>Revise os dados e crie uma nova busca. Nenhuma informação técnica foi exibida.</span>
                </div>
              ) : (
                <>
                  <ChampsLeadFilters filters={filters} onChange={setFilters} />
                  <div className="champs-results-count">
                    {filteredResults.length} de {sourceResults.length} resultado(s) no filtro atual
                  </div>
                  <ChampsLeadTable
                    context={resultContext}
                    emptyDescription={
                      sourceResults.length === 0
                        ? 'A busca foi concluída sem estabelecimentos para exibir.'
                        : 'Ajuste ou limpe os filtros para rever todos os resultados.'
                    }
                    results={filteredResults}
                  />
                  {resultSource === 'api' && activeSearch?.provider === 'google_places' ? (
                    <p className="champs-google-attribution">
                      Dados de estabelecimentos fornecidos por Google Maps
                    </p>
                  ) : null}
                </>
              )}
            </section>
          </>
        ) : null}

        {!isOpeningSearch && !resultsError && !hasSelectedSource ? (
          <section className="champs-panel champs-empty-state champs-empty-state--results">
            <strong>Resultados prontos para a próxima garimpagem.</strong>
            <span>Crie uma busca ou abra um item do histórico para revisar todos os leads encontrados.</span>
          </section>
        ) : null}
      </section>

      <ChampsSearchHistory
        activeSearchId={activeSearchId}
        error={historyError}
        isLoading={isHistoryLoading}
        meta={historyMeta}
        onOpen={(searchId) => void openSearch(searchId)}
        onPageChange={(page) => void loadHistory(page)}
        onRetry={() => void loadHistory(historyMeta?.currentPage ?? 1)}
        searches={searches}
      />
    </main>
  )
}

function downloadCsvContent(filename: string, csv: string) {
  const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')

  anchor.href = url
  anchor.download = filename
  anchor.click()

  URL.revokeObjectURL(url)
}

function focusResultsPanel() {
  window.requestAnimationFrame(() => {
    document.getElementById('champs-results')?.scrollIntoView({ behavior: 'smooth', block: 'start' })
  })
}
