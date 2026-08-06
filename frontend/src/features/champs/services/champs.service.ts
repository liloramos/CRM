import { ApiError, requestJson } from '../../../services/crm.service'

const CHAMPS_API_PATH = '/api/champs'

export const CHAMPS_MAX_RESULTS = 20

export type ChampsSearchStatus =
  | 'pending'
  | 'processing'
  | 'completed'
  | 'partially_completed'
  | 'failed'

export type ChampsArchiveFilter = 'active' | 'only' | 'all'

export type ChampsLeadClassification =
  | 'Baixo potencial'
  | 'Potencial médio'
  | 'Bom potencial'
  | 'Alta prioridade'

export type ChampsLeadStage =
  | 'new'
  | 'reviewing'
  | 'interested'
  | 'contacted'
  | 'awaiting_response'
  | 'meeting_scheduled'
  | 'client'
  | 'lost'
  | 'discarded'

export type ChampsLeadPriority = 'low' | 'normal' | 'high' | 'urgent'

export type ChampsLeadAssignee = {
  id: number
  name: string
}

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
  isFavorite: boolean
  pipelineStage: ChampsLeadStage
  priority: ChampsLeadPriority
  assignedUserId: number | null
  assignedUser: ChampsLeadAssignee | null
  nextFollowUpAt: string | null
  lastContactedAt: string | null
  commercialNotes: string | null
  archivedAt: string | null
  activitiesCount?: number
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
  searchFingerprint: string | null
  excludeSeen: boolean
  minimumScore: number
  status: ChampsSearchStatus
  totalDiscovered: number
  totalSaved: number
  totalQualified: number
  totalScanned: number
  totalSkippedSeen: number
  errorMessage: string | null
  message: string | null
  startedAt: string | null
  completedAt: string | null
  archivedAt: string | null
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
  excludeSeen: boolean
}

export type ChampsSearchFilters = {
  status?: ChampsSearchStatus
  state?: string
  dateFrom?: string
  dateTo?: string
  page?: number
  perPage?: number
  archived?: ChampsArchiveFilter
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
  exclude_seen: boolean
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
      archived: filters.archived,
    })}`,
  )

  return adaptPaginatedResponse(response, adaptChampsSearch)
}

export async function archiveSearch(searchId: number): Promise<ChampsSearch> {
  const response = await requestJson<ResourceEnvelope>(`${CHAMPS_API_PATH}/searches/${searchId}/archive`, {
    method: 'PATCH',
  })

  return adaptChampsSearch(response.data)
}

export async function restoreSearch(searchId: number): Promise<ChampsSearch> {
  const response = await requestJson<ResourceEnvelope>(`${CHAMPS_API_PATH}/searches/${searchId}/restore`, {
    method: 'PATCH',
  })

  return adaptChampsSearch(response.data)
}

export async function archiveAllSearches(): Promise<number> {
  const response = await requestJson<unknown>(`${CHAMPS_API_PATH}/searches/archive-all`, {
    method: 'POST',
  })
  const resource = asRecord(response)

  return requiredNumber(resource.archived_count)
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
    exclude_seen: payload.excludeSeen,
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
    searchFingerprint: nullableString(resource.search_fingerprint),
    excludeSeen: requiredBoolean(resource.exclude_seen),
    minimumScore: requiredNumber(resource.minimum_score),
    status: adaptSearchStatus(resource.status),
    totalDiscovered: requiredNumber(resource.total_discovered),
    totalSaved: requiredNumber(resource.total_saved),
    totalQualified: requiredNumber(resource.total_qualified),
    totalScanned: requiredNumber(resource.total_scanned),
    totalSkippedSeen: requiredNumber(resource.total_skipped_seen),
    errorMessage: nullableString(resource.error_message),
    message: nullableString(resource.message),
    startedAt: nullableString(resource.started_at),
    completedAt: nullableString(resource.completed_at),
    archivedAt: nullableString(resource.archived_at),
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
    isFavorite: optionalBoolean(resource.is_favorite) ?? false,
    pipelineStage: optionalLeadStage(resource.pipeline_stage) ?? 'new',
    priority: optionalLeadPriority(resource.priority) ?? 'normal',
    assignedUserId: nullableNumber(resource.assigned_user_id),
    assignedUser: optionalAssignee(resource.assigned_user),
    nextFollowUpAt: nullableString(resource.next_follow_up_at),
    lastContactedAt: nullableString(resource.last_contacted_at),
    commercialNotes: nullableString(resource.commercial_notes),
    archivedAt: nullableString(resource.archived_at),
    ...(optionalNumber(resource.activities_count) === undefined
      ? {}
      : { activitiesCount: optionalNumber(resource.activities_count) }),
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

function optionalLeadStage(value: unknown): ChampsLeadStage | undefined {
  if (
    value === 'new'
    || value === 'reviewing'
    || value === 'interested'
    || value === 'contacted'
    || value === 'awaiting_response'
    || value === 'meeting_scheduled'
    || value === 'client'
    || value === 'lost'
    || value === 'discarded'
  ) {
    return value
  }

  return undefined
}

function optionalLeadPriority(value: unknown): ChampsLeadPriority | undefined {
  if (value === 'low' || value === 'normal' || value === 'high' || value === 'urgent') {
    return value
  }

  return undefined
}

function optionalAssignee(value: unknown): ChampsLeadAssignee | null {
  if (value === null || value === undefined) {
    return null
  }

  const resource = asRecord(value)

  return {
    id: requiredNumber(resource.id),
    name: requiredString(resource.name),
  }
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

function optionalBoolean(value: unknown): boolean | undefined {
  return typeof value === 'boolean' ? value : undefined
}

function stringArray(value: unknown): string[] {
  return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : []
}

function invalidResponse(): never {
  throw new ApiError('A API do Champs retornou uma resposta inválida.', 502)
}
