import { normalizeState, normalizeText, normalizeUsername, parseBoolean, parseNumber } from './champs-normalizers'
import { calculateScore, type LeadClassification, type ScoreCriterion } from './champs-score'

export type RawLead = {
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
}

export type Lead = RawLead & {
  id: string
  score: number
  classification: LeadClassification
  reasons: string[]
  appliedCriteria: ScoreCriterion[]
  importedAt: string
}

type EnrichLeadOptions = {
  id?: string
  importedAt?: string
}

const EMPTY_RAW_LEAD: RawLead = {
  instagramUsername: '',
  displayName: '',
  city: '',
  state: '',
  followersCount: 0,
  website: '',
  phone: '',
  email: '',
  recentPostsCount: 0,
  isBusinessProfile: false,
}

export function normalizeRawLead(rawLead: Partial<RawLead>): RawLead {
  return {
    instagramUsername: normalizeUsername(rawLead.instagramUsername),
    displayName: normalizeText(rawLead.displayName),
    city: normalizeText(rawLead.city),
    state: normalizeState(rawLead.state),
    followersCount: parseNumber(rawLead.followersCount),
    website: normalizeText(rawLead.website),
    phone: normalizeText(rawLead.phone),
    email: normalizeText(rawLead.email),
    recentPostsCount: parseNumber(rawLead.recentPostsCount),
    isBusinessProfile: parseBoolean(rawLead.isBusinessProfile),
  }
}

export function enrichLead(rawLead: Partial<RawLead>, options: EnrichLeadOptions = {}): Lead {
  const normalizedLead = normalizeRawLead(rawLead)
  const { appliedCriteria, classification, reasons, score } = calculateScore(normalizedLead)

  return {
    ...normalizedLead,
    id: options.id ?? createId(),
    score,
    classification,
    reasons,
    appliedCriteria,
    importedAt: options.importedAt ?? new Date().toISOString(),
  }
}

export function mergeLeads(current: Lead[], incoming: Lead[]): Lead[] {
  const byUsername = new Map<string, Lead>()

  for (const lead of current) {
    const normalizedLead = normalizeLead(lead)

    if (normalizedLead.instagramUsername) {
      byUsername.set(normalizedLead.instagramUsername.toLowerCase(), normalizedLead)
    }
  }

  for (const lead of incoming) {
    const normalizedLead = normalizeLead(lead)

    if (!normalizedLead.instagramUsername) {
      continue
    }

    const key = normalizedLead.instagramUsername.toLowerCase()
    const existing = byUsername.get(key)

    byUsername.set(key, existing ? mergeDuplicateLead(existing, normalizedLead) : normalizedLead)
  }

  return Array.from(byUsername.values())
}

export function leadFromStoredValue(value: unknown): Lead | null {
  if (!value || typeof value !== 'object') {
    return null
  }

  const record = value as Partial<Lead>
  const rawLead = normalizeRawLead({
    instagramUsername: record.instagramUsername,
    displayName: record.displayName,
    city: record.city,
    state: record.state,
    followersCount: record.followersCount,
    website: record.website,
    phone: record.phone,
    email: record.email,
    recentPostsCount: record.recentPostsCount,
    isBusinessProfile: record.isBusinessProfile,
  })

  if (!rawLead.instagramUsername) {
    return null
  }

  return enrichLead(rawLead, {
    id: typeof record.id === 'string' && record.id ? record.id : undefined,
    importedAt: typeof record.importedAt === 'string' && record.importedAt ? record.importedAt : undefined,
  })
}

export function demoLeads(): Lead[] {
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

  return examples.map((lead) => enrichLead(lead))
}

function normalizeLead(lead: Lead): Lead {
  return enrichLead(lead, {
    id: lead.id,
    importedAt: lead.importedAt,
  })
}

function mergeDuplicateLead(existing: Lead, incoming: Lead): Lead {
  const primary = leadCompleteness(incoming) > leadCompleteness(existing) ? incoming : existing
  const secondary = primary === incoming ? existing : incoming
  const rawLead: RawLead = {
    ...EMPTY_RAW_LEAD,
    instagramUsername: existing.instagramUsername,
    displayName: firstFilled(primary.displayName, secondary.displayName),
    city: firstFilled(primary.city, secondary.city),
    state: firstFilled(primary.state, secondary.state),
    followersCount: Math.max(existing.followersCount, incoming.followersCount),
    website: firstFilled(primary.website, secondary.website),
    phone: firstFilled(primary.phone, secondary.phone),
    email: firstFilled(primary.email, secondary.email),
    recentPostsCount: Math.max(existing.recentPostsCount, incoming.recentPostsCount),
    isBusinessProfile: existing.isBusinessProfile || incoming.isBusinessProfile,
  }

  return enrichLead(rawLead, {
    id: existing.id,
    importedAt: existing.importedAt,
  })
}

function firstFilled(primary: string, fallback: string): string {
  return primary || fallback
}

function leadCompleteness(lead: RawLead): number {
  return [
    lead.instagramUsername,
    lead.displayName,
    lead.city,
    lead.state,
    lead.website,
    lead.phone,
    lead.email,
  ].filter(Boolean).length +
    (lead.followersCount > 0 ? 1 : 0) +
    (lead.recentPostsCount > 0 ? 1 : 0) +
    (lead.isBusinessProfile ? 1 : 0)
}

function createId(): string {
  return globalThis.crypto?.randomUUID?.() ?? `${Date.now()}-${Math.random().toString(16).slice(2)}`
}
