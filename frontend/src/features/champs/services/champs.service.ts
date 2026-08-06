import { ApiError, requestJson } from '../../../services/crm.service'

const CHAMPS_API_PATH = '/api/champs'

export const CHAMPS_MAX_RESULTS = 20

export type ChampsSearchStatus =
  | 'pending'
  | 'processing'
  | 'completed'
  | 'partially_completed'
  | 'failed'

export type ChampsLeadClassification =
  | 'Baixo potencial'
  | 'Potencial médio'
  | 'Bom potencial'
  | 'Alta prioridade'

export type ChampsScoreCriterion = {
  met: boolean
  points: number
  maximumPoints: number
}

export type ChampsLead = {
  id: number
  provider: string
  externalId: string
  name: string
  formattedAddress: string | null
  city: string | null
  state: string | null
  phone: string | null
  email: string | null
  website: string | null
  rating: number | null
  userRatingCount: number
  businessStatus: string | null
  instagramUsername: string | null
  instagramProfileUrl: string | null
  instagramFollowersCount: number
  instagramMediaCount: number
  instagramIsProfessional: boolean
  score?: number
  classification?: ChampsLeadClassification
  reasons?: string[]
  criteria?: Record<string, ChampsScoreCriterion>
  qualified?: boolean
  createdAt: string | null
  updatedAt: string | null
}

export type ChampsSearchResult = {
  id: number
  searchId: number
  leadId: number
  score: number
  classification: ChampsLeadClassification
  reasons: string[]
  criteria: Record<string, ChampsScoreCriterion>
  qualified: boolean
  position: number | null
  lead: ChampsLead | null
  createdAt: string | null
  updatedAt: string | null
}

export type ChampsSearch = {
  id: number
  name: string
  niche: string
  city: string
  state: string
  requestedLimit: number
  provider: string
  minimumScore: number
  status: ChampsSearchStatus
  totalDiscovered: number
  totalSaved: number
  totalQualified: number
  errorMessage: string | null
  startedAt: string | null
  completedAt: string | null
  createdAt: string | null
  updatedAt: string | null
  results: ChampsSearchResult[]
  resultsLoaded: boolean
}

export type CreateChampsSearchPayload = {
  name?: string
  niche: string
  city: string
  state: string
  limit: number
  minimumScore: number
}

export type ChampsSearchFilters = {
  status?: ChampsSearchStatus
  state?: string
  dateFrom?: string
  dateTo?: string
  page?: number
  perPage?: number
}

export type ChampsLeadFilters = {
  searchId?: number
  state?: string
  minimumScore?: number
  qualified?: boolean
  classification?: ChampsLeadClassification
  query?: string
  orderBy?: 'score' | 'name' | 'rating' | 'created_at'
  direction?: 'asc' | 'desc'
  page?: number
  perPage?: number
}

export type PaginationLinks = {
  first: string | null
  last: string | null
  previous: string | null
  next: string | null
}

export type PaginationMeta = {
  currentPage: number
  from: number | null
  lastPage: number
  path: string
  perPage: number
  to: number | null
  total: number
}

export type PaginatedResponse<T> = {
  data: T[]
  links: PaginationLinks
  meta: PaginationMeta
}

type CreateSearchRequest = {
  name?: string
  niche: string
  city: string
  state: string
  limit: number
  minimum_score: number
}

type ResourceEnvelope = {
  data: unknown
}

type CollectionEnvelope = {
  data: unknown
  links?: unknown
  meta?: unknown
}

export async function createSearch(payload: CreateChampsSearchPayload): Promise<ChampsSearch> {
  const response = await requestJson<ResourceEnvelope>(`${CHAMPS_API_PATH}/searches`, {
    body: JSON.stringify(serializeCreateSearchPayload(payload)),
    method: 'POST',
  })

  return adaptChampsSearch(response.data)
}

export async function listSearches(filters: ChampsSearchFilters = {}): Promise<PaginatedResponse<ChampsSearch>> {
  const response = await requestJson<CollectionEnvelope>(
    `${CHAMPS_API_PATH}/searches${buildQueryString({
      status: filters.status,
      state: filters.state?.trim().toUpperCase(),
      date_from: filters.dateFrom,
      date_to: filters.dateTo,
      page: filters.page,
      per_page: filters.perPage,
    })}`,
  )

  return adaptPaginatedResponse(response, adaptChampsSearch)
}

export async function getSearch(searchId: number): Promise<ChampsSearch> {
  const response = await requestJson<ResourceEnvelope>(`${CHAMPS_API_PATH}/searches/${searchId}`)

  return adaptChampsSearch(response.data)
}

export async function listLeads(filters: ChampsLeadFilters = {}): Promise<PaginatedResponse<ChampsLead>> {
  const response = await requestJson<CollectionEnvelope>(
    `${CHAMPS_API_PATH}/leads${buildQueryString({
      search_id: filters.searchId,
      state: filters.state?.trim().toUpperCase(),
      minimum_score: filters.minimumScore,
      qualified: filters.qualified,
      classification: filters.classification,
      query: filters.query?.trim(),
      order_by: filters.orderBy,
      direction: filters.direction,
      page: filters.page,
      per_page: filters.perPage,
    })}`,
  )

  return adaptPaginatedResponse(response, adaptChampsLead)
}

export function serializeCreateSearchPayload(payload: CreateChampsSearchPayload): CreateSearchRequest {
  const serialized: CreateSearchRequest = {
    niche: payload.niche.trim(),
    city: payload.city.trim(),
    state: payload.state.trim().toUpperCase(),
    limit: Math.trunc(payload.limit),
    minimum_score: Math.trunc(payload.minimumScore),
  }
  const name = payload.name?.trim()

  if (name) {
    serialized.name = name
  }

  return serialized
}

export function adaptChampsSearch(value: unknown): ChampsSearch {
  const resource = asRecord(value)
  const rawResults = resource.results

  return {
    id: requiredNumber(resource.id),
    name: requiredString(resource.name),
    niche: requiredString(resource.niche),
    city: requiredString(resource.city),
    state: requiredString(resource.state),
    requestedLimit: requiredNumber(resource.requested_limit),
    provider: requiredString(resource.provider),
    minimumScore: requiredNumber(resource.minimum_score),
    status: adaptSearchStatus(resource.status),
    totalDiscovered: requiredNumber(resource.total_discovered),
    totalSaved: requiredNumber(resource.total_saved),
    totalQualified: requiredNumber(resource.total_qualified),
    errorMessage: nullableString(resource.error_message),
    startedAt: nullableString(resource.started_at),
    completedAt: nullableString(resource.completed_at),
    createdAt: nullableString(resource.created_at),
    updatedAt: nullableString(resource.updated_at),
    results: Array.isArray(rawResults) ? rawResults.map(adaptChampsSearchResult) : [],
    resultsLoaded: Array.isArray(rawResults),
  }
}

export function adaptChampsSearchResult(value: unknown): ChampsSearchResult {
  const resource = asRecord(value)

  return {
    id: requiredNumber(resource.id),
    searchId: requiredNumber(resource.search_id),
    leadId: requiredNumber(resource.lead_id),
    score: requiredNumber(resource.score),
    classification: adaptClassification(resource.classification),
    reasons: stringArray(resource.reasons),
    criteria: adaptCriteria(resource.criteria),
    qualified: requiredBoolean(resource.qualified),
    position: nullableNumber(resource.position),
    lead: isRecord(resource.lead) ? adaptChampsLead(resource.lead) : null,
    createdAt: nullableString(resource.created_at),
    updatedAt: nullableString(resource.updated_at),
  }
}

export function adaptChampsLead(value: unknown): ChampsLead {
  const resource = asRecord(value)
  const score = optionalNumber(resource.score)
  const classification = optionalClassification(resource.classification)
  const reasons = Array.isArray(resource.reasons) ? stringArray(resource.reasons) : undefined
  const criteria = isRecord(resource.criteria) ? adaptCriteria(resource.criteria) : undefined
  const qualified = typeof resource.qualified === 'boolean' ? resource.qualified : undefined

  return {
    id: requiredNumber(resource.id),
    provider: requiredString(resource.provider),
    externalId: requiredString(resource.external_id),
    name: requiredString(resource.name),
    formattedAddress: nullableString(resource.formatted_address),
    city: nullableString(resource.city),
    state: nullableString(resource.state),
    phone: nullableString(resource.phone),
    email: nullableString(resource.email),
    website: nullableString(resource.website),
    rating: nullableNumber(resource.rating),
    userRatingCount: requiredNumber(resource.user_rating_count),
    businessStatus: nullableString(resource.business_status),
    instagramUsername: nullableString(resource.instagram_username),
    instagramProfileUrl: nullableString(resource.instagram_profile_url),
    instagramFollowersCount: requiredNumber(resource.instagram_followers_count),
    instagramMediaCount: requiredNumber(resource.instagram_media_count),
    instagramIsProfessional: requiredBoolean(resource.instagram_is_professional),
    ...(score === undefined ? {} : { score }),
    ...(classification === undefined ? {} : { classification }),
    ...(reasons === undefined ? {} : { reasons }),
    ...(criteria === undefined ? {} : { criteria }),
    ...(qualified === undefined ? {} : { qualified }),
    createdAt: nullableString(resource.created_at),
    updatedAt: nullableString(resource.updated_at),
  }
}

export function champsErrorMessage(error: unknown, context: 'create' | 'load' = 'load'): string {
  if (error instanceof ApiError) {
    if (error.status === 401 || error.status === 419) {
      return 'Sua sessão expirou. Entre novamente para continuar.'
    }

    if (error.status === 422) {
      return 'Revise os campos informados e tente novamente.'
    }

    if (error.status === 502 && context === 'create') {
      return 'Não foi possível concluir a consulta agora. Aguarde um instante e tente novamente.'
    }

    if (error.status >= 500) {
      return 'A API do Champs está temporariamente indisponível. Tente novamente em instantes.'
    }
  }

  if (error instanceof TypeError) {
    return 'Não foi possível conectar à API do Champs. Verifique a conexão e tente novamente.'
  }

  return context === 'create'
    ? 'Não foi possível concluir a garimpagem. Tente novamente.'
    : 'Não foi possível carregar os dados do Champs. Tente novamente.'
}

function adaptPaginatedResponse<T>(
  response: CollectionEnvelope,
  adapter: (value: unknown) => T,
): PaginatedResponse<T> {
  const data = Array.isArray(response.data) ? response.data.map(adapter) : invalidResponse()
  const links = asRecord(response.links)
  const meta = asRecord(response.meta)

  return {
    data,
    links: {
      first: nullableString(links.first),
      last: nullableString(links.last),
      previous: nullableString(links.prev),
      next: nullableString(links.next),
    },
    meta: {
      currentPage: requiredNumber(meta.current_page),
      from: nullableNumber(meta.from),
      lastPage: requiredNumber(meta.last_page),
      path: requiredString(meta.path),
      perPage: requiredNumber(meta.per_page),
      to: nullableNumber(meta.to),
      total: requiredNumber(meta.total),
    },
  }
}

function buildQueryString(values: Record<string, string | number | boolean | undefined>): string {
  const query = new URLSearchParams()

  for (const [key, value] of Object.entries(values)) {
    if (value === undefined || value === '') {
      continue
    }

    query.set(key, String(value))
  }

  const serialized = query.toString()

  return serialized ? `?${serialized}` : ''
}

function adaptCriteria(value: unknown): Record<string, ChampsScoreCriterion> {
  if (!isRecord(value)) {
    return {}
  }

  return Object.fromEntries(
    Object.entries(value).flatMap(([key, criterion]) => {
      if (!isRecord(criterion)) {
        return []
      }

      return [[
        key,
        {
          met: requiredBoolean(criterion.met),
          points: requiredNumber(criterion.points),
          maximumPoints: requiredNumber(criterion.maximum_points),
        },
      ]]
    }),
  )
}

function adaptSearchStatus(value: unknown): ChampsSearchStatus {
  if (
    value === 'pending'
    || value === 'processing'
    || value === 'completed'
    || value === 'partially_completed'
    || value === 'failed'
  ) {
    return value
  }

  return invalidResponse()
}

function adaptClassification(value: unknown): ChampsLeadClassification {
  const classification = optionalClassification(value)

  return classification ?? invalidResponse()
}

function optionalClassification(value: unknown): ChampsLeadClassification | undefined {
  if (
    value === 'Baixo potencial'
    || value === 'Potencial médio'
    || value === 'Bom potencial'
    || value === 'Alta prioridade'
  ) {
    return value
  }

  return undefined
}

function asRecord(value: unknown): Record<string, unknown> {
  if (!isRecord(value)) {
    return invalidResponse()
  }

  return value
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function requiredString(value: unknown): string {
  return typeof value === 'string' ? value : invalidResponse()
}

function nullableString(value: unknown): string | null {
  return value === null || value === undefined ? null : requiredString(value)
}

function requiredNumber(value: unknown): number {
  return typeof value === 'number' && Number.isFinite(value) ? value : invalidResponse()
}

function optionalNumber(value: unknown): number | undefined {
  return typeof value === 'number' && Number.isFinite(value) ? value : undefined
}

function nullableNumber(value: unknown): number | null {
  return value === null || value === undefined ? null : requiredNumber(value)
}

function requiredBoolean(value: unknown): boolean {
  return typeof value === 'boolean' ? value : invalidResponse()
}

function stringArray(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : []
}

function invalidResponse(): never {
  throw new ApiError('A API do Champs retornou uma resposta inválida.', 502)
}
