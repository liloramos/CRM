import { ApiError, requestJson } from '../../../services/crm.service'
import {
  adaptChampsLead,
  type ChampsLead,
  type ChampsLeadAssignee,
  type ChampsLeadPriority,
  type ChampsLeadStage,
  type PaginatedResponse,
  type PaginationLinks,
  type PaginationMeta,
} from './champs.service'

const CHAMPS_API_PATH = '/api/champs'

export type SavedLeadsArchiveFilter = 'active' | 'only' | 'all'
export type SavedLeadsFollowUpFilter = 'overdue' | 'today' | 'upcoming' | 'none'
export type SavedLeadsOrderBy = 'updated_at' | 'name' | 'score' | 'next_follow_up_at' | 'priority'
export type ChampsLeadActivityType =
  | 'note'
  | 'stage_changed'
  | 'favorite_added'
  | 'favorite_removed'
  | 'contacted'
  | 'follow_up_scheduled'
  | 'assigned'
  | 'exported'

export type ChampsLeadActivity = {
  id: number
  type: ChampsLeadActivityType
  description: string | null
  metadata: Record<string, unknown> | null
  user: ChampsLeadAssignee | null
  createdAt: string | null
  updatedAt: string | null
}

export type SavedLeadsSummary = {
  saved: number
  interested: number
  contacted: number
  meetings: number
  overdue: number
}

export type SavedLeadsFilters = {
  query?: string
  pipelineStage?: ChampsLeadStage
  priority?: ChampsLeadPriority
  assignedUserId?: number | 'unassigned'
  state?: string
  minimumScore?: number
  followUp?: SavedLeadsFollowUpFilter
  hasInstagram?: boolean
  hasWebsite?: boolean
  archived?: SavedLeadsArchiveFilter
  orderBy?: SavedLeadsOrderBy
  direction?: 'asc' | 'desc'
  page?: number
  perPage?: number
}

export type SavedLeadsResponse = PaginatedResponse<ChampsLead> & {
  summary: SavedLeadsSummary
  assignees: ChampsLeadAssignee[]
}

export type UpdateLeadPipelinePayload = {
  pipelineStage?: ChampsLeadStage
  priority?: ChampsLeadPriority
}

export type CreateLeadActivityPayload = {
  type: Extract<ChampsLeadActivityType, 'note' | 'contacted' | 'exported'>
  description?: string
  occurredAt?: string
}

type ResourceEnvelope = {
  data: unknown
}

type CollectionEnvelope = {
  data: unknown
  links?: unknown
  meta?: unknown
  summary?: unknown
  assignees?: unknown
}

export async function listSavedLeads(filters: SavedLeadsFilters = {}): Promise<SavedLeadsResponse> {
  const response = await requestJson<CollectionEnvelope>(
    `${CHAMPS_API_PATH}/saved-leads${buildQueryString(serializeSavedLeadsFilters(filters))}`,
  )

  return {
    ...adaptPaginatedResponse(response, adaptChampsLead),
    summary: adaptSummary(response.summary),
    assignees: Array.isArray(response.assignees) ? response.assignees.map(adaptAssignee) : [],
  }
}

export async function updateLeadFavorite(leadId: number, isFavorite: boolean): Promise<ChampsLead> {
  return updateLeadResource(leadId, 'favorite', { is_favorite: isFavorite })
}

export async function updateLeadPipeline(
  leadId: number,
  payload: UpdateLeadPipelinePayload,
): Promise<ChampsLead> {
  const body: Record<string, string> = {}

  if (payload.pipelineStage) {
    body.pipeline_stage = payload.pipelineStage
  }

  if (payload.priority) {
    body.priority = payload.priority
  }

  return updateLeadResource(leadId, 'pipeline', body)
}

export async function updateLeadAssignment(
  leadId: number,
  assignedUserId: number | null,
): Promise<ChampsLead> {
  return updateLeadResource(leadId, 'assignment', { assigned_user_id: assignedUserId })
}

export async function updateLeadFollowUp(
  leadId: number,
  nextFollowUpAt: string | null,
): Promise<ChampsLead> {
  return updateLeadResource(leadId, 'follow-up', { next_follow_up_at: nextFollowUpAt })
}

export async function archiveSavedLead(leadId: number): Promise<ChampsLead> {
  return updateLeadResource(leadId, 'archive')
}

export async function restoreSavedLead(leadId: number): Promise<ChampsLead> {
  return updateLeadResource(leadId, 'restore')
}

export async function createLeadActivity(
  leadId: number,
  payload: CreateLeadActivityPayload,
): Promise<ChampsLeadActivity> {
  const response = await requestJson<ResourceEnvelope>(`${CHAMPS_API_PATH}/leads/${leadId}/activities`, {
    body: JSON.stringify(serializeLeadActivity(payload)),
    method: 'POST',
  })

  return adaptLeadActivity(response.data)
}

export async function listLeadActivities(
  leadId: number,
  page = 1,
): Promise<PaginatedResponse<ChampsLeadActivity>> {
  const response = await requestJson<CollectionEnvelope>(
    `${CHAMPS_API_PATH}/leads/${leadId}/activities?page=${page}&per_page=50`,
  )

  return adaptPaginatedResponse(response, adaptLeadActivity)
}

export function serializeSavedLeadsFilters(filters: SavedLeadsFilters): Record<string, string | number | boolean | undefined> {
  return {
    query: filters.query?.trim(),
    pipeline_stage: filters.pipelineStage,
    priority: filters.priority,
    assigned_user_id: filters.assignedUserId,
    state: filters.state?.trim().toUpperCase(),
    minimum_score: filters.minimumScore,
    follow_up: filters.followUp,
    has_instagram: filters.hasInstagram,
    has_website: filters.hasWebsite,
    archived: filters.archived,
    order_by: filters.orderBy,
    direction: filters.direction,
    page: filters.page,
    per_page: filters.perPage,
  }
}

export function serializeLeadActivity(payload: CreateLeadActivityPayload): Record<string, string> {
  const serialized: Record<string, string> = { type: payload.type }
  const description = payload.description?.trim()

  if (description) {
    serialized.description = description
  }

  if (payload.occurredAt) {
    serialized.occurred_at = payload.occurredAt
  }

  return serialized
}

export function adaptLeadActivity(value: unknown): ChampsLeadActivity {
  const resource = asRecord(value)

  return {
    id: requiredNumber(resource.id),
    type: adaptActivityType(resource.type),
    description: nullableString(resource.description),
    metadata: nullableRecord(resource.metadata),
    user: resource.user === null || resource.user === undefined ? null : adaptAssignee(resource.user),
    createdAt: nullableString(resource.created_at),
    updatedAt: nullableString(resource.updated_at),
  }
}

async function updateLeadResource(
  leadId: number,
  action: 'favorite' | 'pipeline' | 'assignment' | 'follow-up' | 'archive' | 'restore',
  payload?: Record<string, string | number | boolean | null>,
): Promise<ChampsLead> {
  const response = await requestJson<ResourceEnvelope>(`${CHAMPS_API_PATH}/leads/${leadId}/${action}`, {
    ...(payload ? { body: JSON.stringify(payload) } : {}),
    method: 'PATCH',
  })

  return adaptChampsLead(response.data)
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
    } satisfies PaginationLinks,
    meta: {
      currentPage: requiredNumber(meta.current_page),
      from: nullableNumber(meta.from),
      lastPage: requiredNumber(meta.last_page),
      path: requiredString(meta.path),
      perPage: requiredNumber(meta.per_page),
      to: nullableNumber(meta.to),
      total: requiredNumber(meta.total),
    } satisfies PaginationMeta,
  }
}

function adaptSummary(value: unknown): SavedLeadsSummary {
  const resource = asRecord(value)

  return {
    saved: requiredNumber(resource.saved),
    interested: requiredNumber(resource.interested),
    contacted: requiredNumber(resource.contacted),
    meetings: requiredNumber(resource.meetings),
    overdue: requiredNumber(resource.overdue),
  }
}

function adaptAssignee(value: unknown): ChampsLeadAssignee {
  const resource = asRecord(value)

  return {
    id: requiredNumber(resource.id),
    name: requiredString(resource.name),
  }
}

function adaptActivityType(value: unknown): ChampsLeadActivityType {
  if (
    value === 'note'
    || value === 'stage_changed'
    || value === 'favorite_added'
    || value === 'favorite_removed'
    || value === 'contacted'
    || value === 'follow_up_scheduled'
    || value === 'assigned'
    || value === 'exported'
  ) {
    return value
  }

  return invalidResponse()
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

function nullableNumber(value: unknown): number | null {
  return value === null || value === undefined ? null : requiredNumber(value)
}

function nullableRecord(value: unknown): Record<string, unknown> | null {
  return value === null || value === undefined ? null : asRecord(value)
}

function invalidResponse(): never {
  throw new ApiError('A API do Champs retornou uma resposta inválida.', 502)
}
