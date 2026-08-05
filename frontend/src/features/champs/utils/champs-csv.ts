import { enrichLead, type Lead, type RawLead } from './champs-leads'
import { normalizeHeader, normalizeState, normalizeText, normalizeUsername, parseBoolean, parseNumber } from './champs-normalizers'

export type ParsedCsv = {
  leads: Lead[]
  rejected: number
}

const USERNAME_ALIASES = ['instagram_username', 'instagram', 'username', 'arroba', 'perfil']
const DISPLAY_NAME_ALIASES = ['display_name', 'name', 'nome', 'empresa']
const CITY_ALIASES = ['city', 'cidade']
const STATE_ALIASES = ['state', 'estado', 'uf']
const FOLLOWERS_ALIASES = ['followers_count', 'followers', 'seguidores']
const WEBSITE_ALIASES = ['website', 'site', 'url']
const PHONE_ALIASES = ['phone', 'telefone', 'whatsapp', 'celular']
const EMAIL_ALIASES = ['email', 'e_mail']
const RECENT_POSTS_ALIASES = ['recent_posts_count', 'recent_posts', 'posts_recentes']
const BUSINESS_PROFILE_ALIASES = ['is_business_profile', 'business_profile', 'perfil_comercial']

const DEFAULT_HEADERS = [
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
]

const ALL_HEADER_ALIASES = new Set([
  ...USERNAME_ALIASES,
  ...DISPLAY_NAME_ALIASES,
  ...CITY_ALIASES,
  ...STATE_ALIASES,
  ...FOLLOWERS_ALIASES,
  ...WEBSITE_ALIASES,
  ...PHONE_ALIASES,
  ...EMAIL_ALIASES,
  ...RECENT_POSTS_ALIASES,
  ...BUSINESS_PROFILE_ALIASES,
])

export function parseCsv(text: string): ParsedCsv {
  const rows = parseDelimitedRows(text).filter((row) => row.some((value) => normalizeText(value)))

  if (rows.length === 0) {
    return { leads: [], rejected: 0 }
  }

  const firstRowHeaders = rows[0].map(normalizeHeader)
  const hasHeader = hasRecognizedHeader(firstRowHeaders)
  const headers = hasHeader ? firstRowHeaders : DEFAULT_HEADERS
  const dataRows = hasHeader ? rows.slice(1) : rows
  const leads: Lead[] = []
  let rejected = 0

  for (const values of dataRows) {
    const row = Object.fromEntries(headers.map((header, index) => [header, normalizeText(values[index])]))
    const instagramUsername = normalizeUsername(getField(row, USERNAME_ALIASES))

    if (!instagramUsername) {
      rejected += 1
      continue
    }

    const rawLead: RawLead = {
      instagramUsername,
      displayName: getField(row, DISPLAY_NAME_ALIASES),
      city: getField(row, CITY_ALIASES),
      state: normalizeState(getField(row, STATE_ALIASES)),
      followersCount: parseNumber(getField(row, FOLLOWERS_ALIASES)),
      website: getField(row, WEBSITE_ALIASES),
      phone: getField(row, PHONE_ALIASES),
      email: getField(row, EMAIL_ALIASES),
      recentPostsCount: parseNumber(getField(row, RECENT_POSTS_ALIASES)),
      isBusinessProfile: parseBoolean(getField(row, BUSINESS_PROFILE_ALIASES)),
    }

    leads.push(enrichLead(rawLead))
  }

  return { leads, rejected }
}

export function parseDelimitedRows(text: string): string[][] {
  if (!text) {
    return []
  }

  const delimiter = detectDelimiter(text)
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

export function buildTemplateCsv(): string {
  return csvRowsToContent([
    DEFAULT_HEADERS,
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

export function buildLeadsExportCsv(leads: Lead[]): string {
  return csvRowsToContent([
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
      'applied_criteria',
    ],
    ...leads.map((lead) => [
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
      lead.appliedCriteria.join(' | '),
    ]),
  ])
}

export function csvRowsToContent(rows: string[][]): string {
  const csv = rows.map((row) => row.map(escapeCsv).join(';')).join('\r\n')

  return `\uFEFF${csv}`
}

export function dateStamp(): string {
  return new Date().toISOString().slice(0, 10)
}

function hasRecognizedHeader(headers: string[]): boolean {
  return headers.some((header) => ALL_HEADER_ALIASES.has(header)) && headers.some((header) => USERNAME_ALIASES.includes(header))
}

function getField(row: Record<string, string>, aliases: string[]): string {
  for (const alias of aliases) {
    const value = row[alias]

    if (value) {
      return normalizeText(value)
    }
  }

  return ''
}

function detectDelimiter(text: string): ',' | ';' {
  const firstRow = firstLogicalRow(text)
  const commas = countDelimiter(firstRow, ',')
  const semicolons = countDelimiter(firstRow, ';')

  return semicolons > commas ? ';' : ','
}

function firstLogicalRow(text: string): string {
  let row = ''
  let inQuotes = false

  for (let index = 0; index < text.length; index += 1) {
    const char = text[index]
    const next = text[index + 1]

    if (char === '"') {
      if (inQuotes && next === '"') {
        row += char
        index += 1
      } else {
        inQuotes = !inQuotes
      }
      continue
    }

    if ((char === '\n' || char === '\r') && !inQuotes) {
      break
    }

    row += char
  }

  return row
}

function countDelimiter(row: string, delimiter: ',' | ';'): number {
  let count = 0
  let inQuotes = false

  for (let index = 0; index < row.length; index += 1) {
    const char = row[index]
    const next = row[index + 1]

    if (char === '"') {
      if (inQuotes && next === '"') {
        index += 1
      } else {
        inQuotes = !inQuotes
      }
      continue
    }

    if (char === delimiter && !inQuotes) {
      count += 1
    }
  }

  return count
}

function escapeCsv(value: string): string {
  const escaped = value.replace(/"/g, '""')

  return /[;"\r\n]/.test(escaped) ? `"${escaped}"` : escaped
}
