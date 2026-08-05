import { useEffect, useMemo, useState, type ChangeEvent } from 'react'
import './ChampsPage.css'
import { FilterSelect, type FilterSelectOption } from './components/FilterSelect'
import { buildLeadsExportCsv, buildTemplateCsv, dateStamp, parseCsv } from './utils/champs-csv'
import { filterLeads, type ChampsStateFilter } from './utils/champs-filters'
import { demoLeads, mergeLeads, type Lead } from './utils/champs-leads'
import { ensureUrl } from './utils/champs-normalizers'
import { scoreTone } from './utils/champs-score'
import { readStoredLeads, writeStoredLeads } from './utils/champs-storage'

const STATE_FILTER_OPTIONS: FilterSelectOption[] = [
  { label: 'Todos', value: 'TODOS' },
  { label: 'São Paulo', value: 'SP' },
  { label: 'Rio de Janeiro', value: 'RJ' },
  { label: 'Outros estados', value: 'OUTROS' },
]

const MINIMUM_SCORE_OPTIONS: FilterSelectOption[] = [
  { label: 'Todos', value: '0' },
  { label: '40+', value: '40' },
  { label: '70+', value: '70' },
  { label: '85+', value: '85' },
]

export function ChampsPage() {
  const [leads, setLeads] = useState<Lead[]>(readStoredLeads)
  const [stateFilter, setStateFilter] = useState<ChampsStateFilter>('TODOS')
  const [minimumScore, setMinimumScore] = useState(0)
  const [query, setQuery] = useState('')
  const [feedback, setFeedback] = useState('')

  useEffect(() => {
    if (!writeStoredLeads(leads)) {
      console.error('Não foi possível salvar os leads no navegador.')
    }
  }, [leads])

  const filteredLeads = useMemo(
    () => filterLeads(leads, { minimumScore, query, stateFilter }),
    [leads, minimumScore, query, stateFilter],
  )

  const stats = useMemo(() => {
    const qualified = leads.filter((lead) => lead.score >= 70).length
    const highPriority = leads.filter((lead) => lead.score >= 85).length
    const spOrRj = leads.filter((lead) => lead.state === 'SP' || lead.state === 'RJ').length
    const average = leads.length
      ? Math.round(leads.reduce((total, lead) => total + lead.score, 0) / leads.length)
      : 0

    return {
      total: leads.length,
      qualified,
      highPriority,
      spOrRj,
      average,
    }
  }, [leads])

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

      setLeads((current) => mergeLeads(current, parsed.leads))
      setFeedback(`${parsed.leads.length} lead(s) importado(s)${rejectedMessage}`)
    } catch {
      setFeedback('Não foi possível ler o CSV. Confira o arquivo e tente novamente.')
    }
  }

  function loadDemo() {
    setLeads((current) => mergeLeads(current, demoLeads()))
    setFeedback('Dados demonstrativos carregados.')
  }

  function clearAll() {
    if (!window.confirm('Remover todos os leads salvos neste navegador?')) {
      return
    }

    setLeads([])
    setFeedback('Todos os leads foram removidos.')
  }

  function exportFilteredLeads() {
    if (filteredLeads.length === 0) {
      setFeedback('Não há leads no filtro atual para exportar.')
      return
    }

    downloadCsvContent(`champs-leads-${dateStamp()}.csv`, buildLeadsExportCsv(filteredLeads))
    setFeedback(`${filteredLeads.length} lead(s) exportado(s).`)
  }

  function downloadTemplate() {
    downloadCsvContent('modelo-importacao-champs.csv', buildTemplateCsv())
  }

  return (
    <main className="champs-page">
      <section className="champs-hero">
        <div>
          <span className="champs-eyebrow">MQ • INTELIGÊNCIA COMERCIAL</span>
          <h1>Qualificação de leads</h1>
          <p>
            Importe perfis comerciais, identifique oportunidades em SP e RJ e priorize leads com maior potencial de contratação.
          </p>
        </div>

        <div className="champs-actions">
          <button className="champs-button champs-button--ghost" onClick={downloadTemplate} type="button">
            Baixar modelo CSV
          </button>

          <button className="champs-button champs-button--secondary" onClick={loadDemo} type="button">
            Carregar demonstração
          </button>

          <label className="champs-button champs-button--primary import-csv-button">
            Importar CSV
            <input accept=".csv,text/csv" hidden onChange={handleCsvImport} type="file" />
          </label>
        </div>
      </section>

      {feedback ? (
        <div className="champs-feedback" role="status">
          {feedback}
          <button aria-label="Fechar mensagem" onClick={() => setFeedback('')} type="button">
            ×
          </button>
        </div>
      ) : null}

      <section className="champs-stats" aria-label="Resumo dos leads">
        <Stat label="Leads importados" value={stats.total} />
        <Stat label="Qualificados (70+)" value={stats.qualified} />
        <Stat label="Alta prioridade (85+)" value={stats.highPriority} />
        <Stat label="Localizados em SP/RJ" value={stats.spOrRj} />
        <Stat label="Score médio" value={stats.average} suffix="/100" />
      </section>

      <section className="champs-panel">
        <div className="champs-panel__header">
          <div>
            <h2>Leads encontrados</h2>
            <p>{filteredLeads.length} resultado(s) no filtro atual</p>
          </div>

          <div className="champs-panel__actions">
            <button className="champs-button champs-button--danger" disabled={leads.length === 0} onClick={clearAll} type="button">
              Limpar dados
            </button>
            <button className="champs-button champs-button--primary" disabled={filteredLeads.length === 0} onClick={exportFilteredLeads} type="button">
              Exportar CSV
            </button>
          </div>
        </div>

        <div className="champs-filters">
          <label>
            Buscar
            <input
              onChange={(event) => setQuery(event.target.value)}
              placeholder="Empresa, @, cidade, site..."
              type="search"
              value={query}
            />
          </label>

          <FilterSelect
            label="Estado"
            onChange={(value) => setStateFilter(value as ChampsStateFilter)}
            options={STATE_FILTER_OPTIONS}
            value={stateFilter}
          />

          <FilterSelect
            label="Score mínimo"
            onChange={(value) => setMinimumScore(Number(value))}
            options={MINIMUM_SCORE_OPTIONS}
            value={String(minimumScore)}
          />
        </div>

        <div className="champs-table-wrap">
          <table className="champs-table">
            <thead>
              <tr>
                <th>Perfil</th>
                <th>Localização</th>
                <th>Seguidores</th>
                <th>Contato</th>
                <th>Score</th>
                <th>Motivos</th>
              </tr>
            </thead>
            <tbody>
              {filteredLeads.length === 0 ? (
                <tr>
                  <td className="champs-empty" colSpan={6}>
                    <strong>Nenhum lead para exibir.</strong>
                    <span>Importe um CSV ou carregue os dados demonstrativos.</span>
                  </td>
                </tr>
              ) : (
                filteredLeads.map((lead) => (
                  <tr key={lead.id}>
                    <td>
                      <div className="champs-profile">
                        <strong>{lead.displayName || `@${lead.instagramUsername}`}</strong>
                        <a href={`https://instagram.com/${lead.instagramUsername}`} rel="noreferrer" target="_blank">
                          @{lead.instagramUsername}
                        </a>
                      </div>
                    </td>
                    <td>
                      {lead.city || 'Não informado'}
                      <small>{lead.state || '—'}</small>
                    </td>
                    <td>{formatNumber(lead.followersCount)}</td>
                    <td>
                      <div className="champs-contact">
                        {lead.website ? (
                          <a href={ensureUrl(lead.website)} rel="noreferrer" target="_blank">
                            Site
                          </a>
                        ) : null}
                        {lead.email ? <span>{lead.email}</span> : null}
                        {lead.phone ? <span>{lead.phone}</span> : null}
                        {!lead.website && !lead.email && !lead.phone ? <span>Não identificado</span> : null}
                      </div>
                    </td>
                    <td>
                      <span className={`champs-score champs-score--${scoreTone(lead.score)}`}>{lead.score}</span>
                      <small>{lead.classification}</small>
                    </td>
                    <td>
                      <ul className="champs-reasons">
                        {lead.reasons.slice(0, 3).map((reason) => (
                          <li key={reason}>{reason}</li>
                        ))}
                      </ul>
                    </td>
                  </tr>
                ))
              )}
            </tbody>
          </table>
        </div>
      </section>
    </main>
  )
}

function Stat({ label, value, suffix = '' }: { label: string; value: number; suffix?: string }) {
  return (
    <article className="champs-stat">
      <span>{label}</span>
      <strong>
        {value}
        {suffix}
      </strong>
    </article>
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

function formatNumber(value: number): string {
  return new Intl.NumberFormat('pt-BR').format(value)
}
