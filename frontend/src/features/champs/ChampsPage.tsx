import { useEffect, useMemo, useState, type ChangeEvent } from 'react'
import './ChampsPage.css'

type LeadClassification = 'Baixo potencial' | 'Potencial médio' | 'Bom potencial' | 'Alta prioridade'

type Lead = {
  id: string
  instagramUsername: string
  displayName: string
  city: string
  state: string
  followersCount: number
  website: string
  phone: string
  email: string
  recentPostsCount: number
  isBusinessProfile: boolean
  score: number
  classification: LeadClassification
  reasons: string[]
  importedAt: string
}

type RawLead = Omit<Lead, 'id' | 'score' | 'classification' | 'reasons' | 'importedAt'>

const STORAGE_KEY = 'champs.mvp.leads.v1'

export function ChampsPage() {
  const [leads, setLeads] = useState<Lead[]>(readStoredLeads)
  const [stateFilter, setStateFilter] = useState('TODOS')
  const [minimumScore, setMinimumScore] = useState(0)
  const [query, setQuery] = useState('')
  const [feedback, setFeedback] = useState('')

  useEffect(() => {
    try {
      window.localStorage.setItem(STORAGE_KEY, JSON.stringify(leads))
    } catch (error) {
      console.error('Não foi possível salvar os leads no navegador.', error)
    }
  }, [leads])

  const filteredLeads = useMemo(() => {
    const normalizedQuery = query.trim().toLocaleLowerCase('pt-BR')

    return leads
      .filter((lead) => {
        if (stateFilter === 'TODOS') {
          return true
        }

        if (stateFilter === 'OUTROS') {
          return lead.state !== 'SP' && lead.state !== 'RJ'
        }

        return lead.state === stateFilter
      })
      .filter((lead) => lead.score >= minimumScore)
      .filter((lead) => {
        if (!normalizedQuery) {
          return true
        }

        return [
          lead.instagramUsername,
          lead.displayName,
          lead.city,
          lead.state,
          lead.website,
          lead.email,
        ].some((value) => value.toLocaleLowerCase('pt-BR').includes(normalizedQuery))
      })
      .sort((a, b) => b.score - a.score || b.followersCount - a.followersCount)
  }, [leads, minimumScore, query, stateFilter])

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

      if (parsed.leads.length === 0) {
        setFeedback('Nenhum lead válido foi encontrado. Confira os nomes das colunas do arquivo.')
        return
      }

      setLeads((current) => mergeLeads(current, parsed.leads))
      setFeedback(
        `${parsed.leads.length} lead(s) importado(s)` +
          (parsed.rejected > 0 ? ` e ${parsed.rejected} linha(s) ignorada(s).` : '.'),
      )
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

    const rows = [
      [
        'instagram_username',
        'display_name',
        'city',
        'state',
        'followers_count',
        'website',
        'phone',
        'email',
        'recent_posts_count',
        'is_business_profile',
        'score',
        'classification',
        'reasons',
      ],
      ...filteredLeads.map((lead) => [
        lead.instagramUsername,
        lead.displayName,
        lead.city,
        lead.state,
        String(lead.followersCount),
        lead.website,
        lead.phone,
        lead.email,
        String(lead.recentPostsCount),
        lead.isBusinessProfile ? 'true' : 'false',
        String(lead.score),
        lead.classification,
        lead.reasons.join(' | '),
      ]),
    ]

    downloadCsv(`champs-leads-${dateStamp()}.csv`, rows)
    setFeedback(`${filteredLeads.length} lead(s) exportado(s).`)
  }

  function downloadTemplate() {
    downloadCsv('modelo-importacao-champs.csv', [
      [
        'instagram_username',
        'display_name',
        'city',
        'state',
        'followers_count',
        'website',
        'phone',
        'email',
        'recent_posts_count',
        'is_business_profile',
      ],
      [
        'clinicaexemplo',
        'Clínica Exemplo',
        'São Paulo',
        'SP',
        '18500',
        'https://clinicaexemplo.com.br',
        '11999999999',
        'contato@clinicaexemplo.com.br',
        '12',
        'true',
      ],
    ])
  }

  return (
    <main className="champs-page">
      <section className="champs-hero">
        <div>
          <span className="champs-eyebrow">CHAMPS • Prospecção inteligente</span>
          <h1>Qualificação de leads</h1>
          <p>
            Importe perfis comerciais, priorize empresas de SP e RJ e exporte uma lista pronta para prospecção.
          </p>
        </div>

        <div className="champs-actions">
          <button className="champs-button champs-button--ghost" onClick={downloadTemplate} type="button">
            Baixar modelo CSV
          </button>

          <button className="champs-button champs-button--secondary" onClick={loadDemo} type="button">
            Carregar demonstração
          </button>

          <label className="champs-button champs-button--primary">
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

          <label>
            Estado
            <select onChange={(event) => setStateFilter(event.target.value)} value={stateFilter}>
              <option value="TODOS">Todos</option>
              <option value="SP">São Paulo</option>
              <option value="RJ">Rio de Janeiro</option>
              <option value="OUTROS">Outros estados</option>
            </select>
          </label>

          <label>
            Score mínimo
            <select onChange={(event) => setMinimumScore(Number(event.target.value))} value={minimumScore}>
              <option value={0}>Todos</option>
              <option value={40}>40+</option>
              <option value={70}>70+</option>
              <option value={85}>85+</option>
            </select>
          </label>
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

function parseCsv(text: string): { leads: Lead[]; rejected: number } {
  const rows = parseDelimitedRows(text)

  if (rows.length < 2) {
    return { leads: [], rejected: 0 }
  }

  const headers = rows[0].map(normalizeHeader)
  const leads: Lead[] = []
  let rejected = 0

  for (const values of rows.slice(1)) {
    if (values.every((value) => !value.trim())) {
      continue
    }

    const row = Object.fromEntries(headers.map((header, index) => [header, values[index]?.trim() ?? '']))
    const instagramUsername = cleanUsername(getField(row, ['instagram_username', 'instagram', 'username', 'arroba', 'perfil']))

    if (!instagramUsername) {
      rejected += 1
      continue
    }

    const rawLead: RawLead = {
      instagramUsername,
      displayName: getField(row, ['display_name', 'name', 'nome', 'empresa']),
      city: getField(row, ['city', 'cidade']),
      state: normalizeState(getField(row, ['state', 'estado', 'uf'])),
      followersCount: parseNumber(getField(row, ['followers_count', 'followers', 'seguidores'])),
      website: getField(row, ['website', 'site', 'url']),
      phone: getField(row, ['phone', 'telefone', 'whatsapp', 'celular']),
      email: getField(row, ['email', 'e_mail']),
      recentPostsCount: parseNumber(getField(row, ['recent_posts_count', 'recent_posts', 'posts_recentes'])),
      isBusinessProfile: parseBoolean(getField(row, ['is_business_profile', 'business_profile', 'perfil_comercial'])),
    }

    leads.push(enrichLead(rawLead))
  }

  return { leads, rejected }
}

function parseDelimitedRows(text: string): string[][] {
  const firstLine = text.split(/\r?\n/, 1)[0] ?? ''
  const delimiter = (firstLine.match(/;/g)?.length ?? 0) > (firstLine.match(/,/g)?.length ?? 0) ? ';' : ','
  const rows: string[][] = []
  let row: string[] = []
  let field = ''
  let inQuotes = false

  for (let index = 0; index < text.length; index += 1) {
    const char = text[index]
    const next = text[index + 1]

    if (char === '"') {
      if (inQuotes && next === '"') {
        field += '"'
        index += 1
      } else {
        inQuotes = !inQuotes
      }
      continue
    }

    if (char === delimiter && !inQuotes) {
      row.push(field)
      field = ''
      continue
    }

    if ((char === '\n' || char === '\r') && !inQuotes) {
      if (char === '\r' && next === '\n') {
        index += 1
      }

      row.push(field)
      rows.push(row)
      row = []
      field = ''
      continue
    }

    field += char
  }

  if (field.length > 0 || row.length > 0) {
    row.push(field)
    rows.push(row)
  }

  return rows
}

function enrichLead(rawLead: RawLead): Lead {
  const { score, reasons } = calculateScore(rawLead)

  return {
    ...rawLead,
    id: createId(),
    score,
    classification: classifyScore(score),
    reasons,
    importedAt: new Date().toISOString(),
  }
}

function calculateScore(lead: RawLead): { score: number; reasons: string[] } {
  let score = 0
  const reasons: string[] = []

  if (lead.state === 'SP' || lead.state === 'RJ') {
    score += 25
    reasons.push('Localizado em SP ou RJ')
  }

  if (lead.website) {
    score += 15
    reasons.push('Possui site próprio')
  }

  if (lead.phone) {
    score += 10
    reasons.push('Telefone ou WhatsApp identificado')
  }

  if (lead.email) {
    score += 10
    reasons.push('E-mail comercial identificado')
  }

  if (lead.isBusinessProfile) {
    score += 10
    reasons.push('Perfil comercial')
  }

  if (lead.recentPostsCount >= 6) {
    score += 10
    reasons.push('Publicação frequente')
  }

  if (lead.followersCount >= 5000) {
    score += 10
    reasons.push('Audiência relevante')
  }

  if (lead.displayName && lead.city) {
    score += 10
    reasons.push('Cadastro estruturado')
  }

  return { score: Math.min(score, 100), reasons }
}

function mergeLeads(current: Lead[], incoming: Lead[]): Lead[] {
  const byUsername = new Map(current.map((lead) => [lead.instagramUsername.toLowerCase(), lead]))

  for (const lead of incoming) {
    byUsername.set(lead.instagramUsername.toLowerCase(), lead)
  }

  return Array.from(byUsername.values())
}

function readStoredLeads(): Lead[] {
  try {
    const stored = window.localStorage.getItem(STORAGE_KEY)
    const parsed = stored ? JSON.parse(stored) : []

    return Array.isArray(parsed) ? parsed : []
  } catch {
    return []
  }
}

function demoLeads(): Lead[] {
  const examples: RawLead[] = [
    {
      instagramUsername: 'clinicaaurora.sp',
      displayName: 'Clínica Aurora',
      city: 'São Paulo',
      state: 'SP',
      followersCount: 18600,
      website: 'https://example.com/clinica-aurora',
      phone: '11999990001',
      email: 'contato@exemplo.com',
      recentPostsCount: 12,
      isBusinessProfile: true,
    },
    {
      instagramUsername: 'studioatlas.rj',
      displayName: 'Studio Atlas',
      city: 'Rio de Janeiro',
      state: 'RJ',
      followersCount: 9400,
      website: 'https://example.com/studio-atlas',
      phone: '',
      email: 'comercial@exemplo.com',
      recentPostsCount: 9,
      isBusinessProfile: true,
    },
    {
      instagramUsername: 'lojaorbita',
      displayName: 'Loja Órbita',
      city: 'Campinas',
      state: 'SP',
      followersCount: 4300,
      website: '',
      phone: '19999990002',
      email: '',
      recentPostsCount: 4,
      isBusinessProfile: true,
    },
    {
      instagramUsername: 'empresaexemplo.pr',
      displayName: 'Empresa Exemplo',
      city: 'Curitiba',
      state: 'PR',
      followersCount: 2600,
      website: '',
      phone: '',
      email: '',
      recentPostsCount: 2,
      isBusinessProfile: false,
    },
  ]

  return examples.map(enrichLead)
}

function normalizeHeader(value: string): string {
  return value
    .replace(/^\uFEFF/, '')
    .trim()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[\s-]+/g, '_')
}

function getField(row: Record<string, string>, aliases: string[]): string {
  for (const alias of aliases) {
    const value = row[alias]
    if (value) {
      return value.trim()
    }
  }

  return ''
}

function normalizeState(value: string): string {
  const normalized = value
    .trim()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toUpperCase()

  if (normalized === 'SAO PAULO') {
    return 'SP'
  }

  if (normalized === 'RIO DE JANEIRO') {
    return 'RJ'
  }

  return normalized.slice(0, 2)
}

function cleanUsername(value: string): string {
  const trimmed = value.trim().replace(/^@/, '')
  const profileMatch = trimmed.match(/instagram\.com\/([^/?#]+)/i)
  return (profileMatch?.[1] ?? trimmed).replace(/\/$/, '').toLowerCase()
}

function parseNumber(value: string): number {
  const digits = value.replace(/[^\d-]/g, '')
  const parsed = Number(digits)
  return Number.isFinite(parsed) ? Math.max(0, parsed) : 0
}

function parseBoolean(value: string): boolean {
  return ['true', '1', 'sim', 'yes', 'y', 'comercial', 'business'].includes(value.trim().toLowerCase())
}

function classifyScore(score: number): LeadClassification {
  if (score >= 85) {
    return 'Alta prioridade'
  }

  if (score >= 70) {
    return 'Bom potencial'
  }

  if (score >= 40) {
    return 'Potencial médio'
  }

  return 'Baixo potencial'
}

function scoreTone(score: number): 'low' | 'medium' | 'good' | 'high' {
  if (score >= 85) {
    return 'high'
  }

  if (score >= 70) {
    return 'good'
  }

  if (score >= 40) {
    return 'medium'
  }

  return 'low'
}

function downloadCsv(filename: string, rows: string[][]) {
  const csv = rows.map((row) => row.map(escapeCsv).join(';')).join('\r\n')
  const blob = new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' })
  const url = URL.createObjectURL(blob)
  const anchor = document.createElement('a')

  anchor.href = url
  anchor.download = filename
  anchor.click()

  URL.revokeObjectURL(url)
}

function escapeCsv(value: string): string {
  const escaped = value.replace(/"/g, '""')
  return /[;"\r\n]/.test(escaped) ? `"${escaped}"` : escaped
}

function ensureUrl(value: string): string {
  return /^https?:\/\//i.test(value) ? value : `https://${value}`
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat('pt-BR').format(value)
}

function dateStamp(): string {
  return new Date().toISOString().slice(0, 10)
}

function createId(): string {
  return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`
}
