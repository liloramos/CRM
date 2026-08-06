import type { ChampsStateFilter } from './champs-filters'
import type { Lead } from './champs-leads'
import { csvRowsToContent } from './champs-csv'
import type {
  ChampsLead,
  ChampsLeadClassification,
  ChampsSearchResult,
  ChampsSearchStatus,
} from '../services/champs.service'

export type ChampsQualificationFilter = 'TODOS' | 'QUALIFICADOS' | 'NAO_QUALIFICADOS'
export type ChampsResultsOrder = 'SCORE_DESC' | 'SCORE_ASC' | 'RATING_DESC' | 'RATING_ASC'

export type ChampsResultFilters = {
  stateFilter: ChampsStateFilter
  qualificationFilter: ChampsQualificationFilter
  minimumScore: number
  query: string
  order: ChampsResultsOrder
}

export type ChampsResultContext = {
  fallbackCity?: string
  fallbackState?: string
}

export type ChampsStatusPresentation = {
  label: string
  tone: 'neutral' | 'processing' | 'success' | 'warning' | 'danger'
}

export function filterSearchResults(
  results: ChampsSearchResult[],
  filters: ChampsResultFilters,
  context: ChampsResultContext = {},
): ChampsSearchResult[] {
  const normalizedQuery = normalizeSearchText(filters.query)

  return [...results]
    .filter((result) => matchesQualification(result, filters.qualificationFilter))
    .filter((result) => matchesState(result, filters.stateFilter, context.fallbackState))
    .filter((result) => result.score >= filters.minimumScore)
    .filter((result) => matchesQuery(result, normalizedQuery, context))
    .sort((first, second) => compareResults(first, second, filters.order))
}

export function buildSearchResultsExportCsv(
  results: ChampsSearchResult[],
  context: ChampsResultContext = {},
): string {
  return csvRowsToContent([
    [
      'position',
      'qualified',
      'name',
      'formatted_address',
      'city',
      'state',
      'phone',
      'email',
      'website',
      'rating',
      'user_rating_count',
      'score',
      'classification',
      'reasons',
      'business_status',
      'instagram_username',
      'provider',
      'external_id',
    ],
    ...results.map((result) => {
      const lead = result.lead

      return [
        result.position === null ? '' : String(result.position),
        result.qualified ? 'true' : 'false',
        lead?.name ?? '',
        lead?.formattedAddress ?? '',
        lead?.city ?? context.fallbackCity ?? '',
        lead?.state ?? context.fallbackState ?? '',
        lead?.phone ?? '',
        lead?.email ?? '',
        lead?.website ?? '',
        lead?.rating === null || lead?.rating === undefined ? '' : String(lead.rating),
        String(lead?.userRatingCount ?? 0),
        String(result.score),
        result.classification,
        result.reasons.join(' | '),
        lead?.businessStatus ?? '',
        lead?.instagramUsername ?? '',
        lead?.provider ?? '',
        lead?.externalId ?? '',
      ]
    }),
  ])
}

export function legacyLeadToSearchResult(lead: Lead, index: number): ChampsSearchResult {
  const leadId = -(index + 1)
  const apiLead: ChampsLead = {
    id: leadId,
    provider: 'csv',
    externalId: lead.id,
    name: lead.displayName || `@${lead.instagramUsername}`,
    formattedAddress: null,
    city: lead.city || null,
    state: lead.state || null,
    phone: lead.phone || null,
    email: lead.email || null,
    website: lead.website || null,
    rating: null,
    userRatingCount: 0,
    businessStatus: null,
    instagramUsername: lead.instagramUsername || null,
    instagramProfileUrl: lead.instagramUsername ? `https://instagram.com/${lead.instagramUsername}` : null,
    instagramFollowersCount: lead.followersCount,
    instagramMediaCount: lead.recentPostsCount,
    instagramIsProfessional: lead.isBusinessProfile,
    isFavorite: false,
    pipelineStage: 'new',
    priority: 'normal',
    assignedUserId: null,
    assignedUser: null,
    nextFollowUpAt: null,
    lastContactedAt: null,
    commercialNotes: null,
    archivedAt: null,
    score: lead.score,
    classification: lead.classification,
    reasons: lead.reasons,
    criteria: {},
    qualified: lead.score >= 70,
    createdAt: lead.importedAt,
    updatedAt: lead.importedAt,
  }

  return {
    id: leadId,
    searchId: 0,
    leadId,
    score: lead.score,
    classification: lead.classification,
    reasons: lead.reasons,
    criteria: {},
    qualified: lead.score >= 70,
    position: index + 1,
    lead: apiLead,
    createdAt: lead.importedAt,
    updatedAt: lead.importedAt,
  }
}

export function searchStatusPresentation(status: ChampsSearchStatus): ChampsStatusPresentation {
  switch (status) {
    case 'pending':
      return { label: 'Pendente', tone: 'neutral' }
    case 'processing':
      return { label: 'Processando', tone: 'processing' }
    case 'completed':
      return { label: 'Concluída', tone: 'success' }
    case 'partially_completed':
      return { label: 'Concluída parcialmente', tone: 'warning' }
    case 'failed':
      return { label: 'Falhou', tone: 'danger' }
  }
}

export function classificationTone(
  classification: ChampsLeadClassification,
): 'low' | 'medium' | 'good' | 'high' {
  switch (classification) {
    case 'Alta prioridade':
      return 'high'
    case 'Bom potencial':
      return 'good'
    case 'Potencial médio':
      return 'medium'
    case 'Baixo potencial':
      return 'low'
  }
}

function matchesQualification(result: ChampsSearchResult, filter: ChampsQualificationFilter): boolean {
  if (filter === 'QUALIFICADOS') {
    return result.qualified
  }

  if (filter === 'NAO_QUALIFICADOS') {
    return !result.qualified
  }

  return true
}

function matchesState(
  result: ChampsSearchResult,
  filter: ChampsStateFilter,
  fallbackState?: string,
): boolean {
  if (filter === 'TODOS') {
    return true
  }

  const state = (result.lead?.state ?? fallbackState ?? '').trim().toUpperCase()

  if (filter === 'OUTROS') {
    return state !== 'SP' && state !== 'RJ'
  }

  return state === filter
}

function matchesQuery(
  result: ChampsSearchResult,
  normalizedQuery: string,
  context: ChampsResultContext,
): boolean {
  if (!normalizedQuery) {
    return true
  }

  const lead = result.lead
  const values = [
    lead?.name,
    lead?.formattedAddress,
    lead?.city ?? context.fallbackCity,
    lead?.state ?? context.fallbackState,
    lead?.phone,
    lead?.email,
    lead?.website,
    lead?.instagramUsername,
    result.classification,
    ...result.reasons,
  ]

  return values.some((value) => normalizeSearchText(value ?? '').includes(normalizedQuery))
}

function compareResults(
  first: ChampsSearchResult,
  second: ChampsSearchResult,
  order: ChampsResultsOrder,
): number {
  const firstRating = first.lead?.rating ?? -1
  const secondRating = second.lead?.rating ?? -1
  const positionTieBreaker = (first.position ?? Number.MAX_SAFE_INTEGER) - (second.position ?? Number.MAX_SAFE_INTEGER)

  switch (order) {
    case 'SCORE_ASC':
      return first.score - second.score || secondRating - firstRating || positionTieBreaker
    case 'RATING_DESC':
      return secondRating - firstRating || second.score - first.score || positionTieBreaker
    case 'RATING_ASC':
      return firstRating - secondRating || second.score - first.score || positionTieBreaker
    case 'SCORE_DESC':
      return second.score - first.score || secondRating - firstRating || positionTieBreaker
  }
}

function normalizeSearchText(value: string): string {
  return value
    .trim()
    .toLocaleLowerCase('pt-BR')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
}
