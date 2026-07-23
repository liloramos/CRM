import { productsMock } from '../mocks/cardapio.mock'
import { customersMock } from '../mocks/clientes.mock'
import { conversationsMock } from '../mocks/conversas.mock'
import {
  dailyFinancialSummaryMock,
  deliveryTasksMock,
  expenseEntriesMock,
  financeEntriesMock,
  integrationsMock,
  paymentMethodSummaryMock,
} from '../mocks/operacional.mock'
import { ordersMock } from '../mocks/pedidos.mock'
import type {
  AdminDailyMenuAdjustmentsResponse,
  AdminMenuComponentsResponse,
  AdminMenuComponent,
  AdminMenuProductsResponse,
  AdminWeeklyMenuItem,
  AdminWeeklyMenuResponse,
  AuthUser,
  ComponentAvailabilityMutationResponse,
  DailyMenuAdjustmentMutationResponse,
  DailyMenuAdjustmentAction,
  DailyMenuSectionKey,
  DailyStructuredMenu,
  EffectiveAvailabilityStatus,
  MenuOption,
  MenuComponentTypeKey,
  OperationalSnapshot,
  PrintPreviewResult,
  Product,
  ProductServiceDayKey,
  SnapshotSource,
  StructuredMenuCatalogResponse,
  StructuredMenuProduct,
  WeeklyMenuServiceDayKey,
} from '../types/crm'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? ''
const MOCK_FALLBACK_ENABLED = import.meta.env.DEV && import.meta.env.VITE_ENABLE_MOCK_FALLBACK === 'true'

let csrfToken: string | null = null

type ApiEnvelope<T> = {
  data: T
  meta?: Record<string, unknown>
}

type SessionResponse = {
  authenticated: boolean
  user: AuthUser | null
}

type SnapshotResponse = {
  snapshot: OperationalSnapshot
  source: SnapshotSource
  fallbackReason?: string
}

type LoginPayload = {
  email: string
  password: string
  remember?: boolean
}

type DraftOrderPayload = {
  payer_customer_id?: string
  fulfillment_type?: 'pickup' | 'delivery' | 'counter'
  general_notes?: string
  kitchen_notes?: string
  pickup_person_name?: string
}

type AddItemPayload = {
  product_id: string
  quantity: number
  item_notes?: string
  beneficiary_name?: string
  options?: Array<{
    product_option_id: string
    quantity?: number
  }>
}

type UpdateMenuOptionAvailabilityPayload = {
  status: 'available' | 'unavailable'
  reason?: string
  date?: string
}

type AutomationModePayload = {
  mode: 'assisted' | 'manual'
  reason?: string
}

export type UpdateMenuProductPayload = {
  date?: string
  name: string
  description: string | null
  price_cents: number
  is_active: boolean
  is_available_by_default: boolean
  display_order: number
  service_days: ProductServiceDayKey[]
}

export type SaveMenuComponentPayload = {
  name: string
  component_type: MenuComponentTypeKey
  description: string | null
  is_active: boolean
  display_order: number
}

export type UpdateComponentAvailabilityPayload = {
  date: string
  status: EffectiveAvailabilityStatus
  reason?: string | null
  replacement_component_id?: number | null
}

export type UpsertWeeklyMenuComponentPayload = {
  service_day: WeeklyMenuServiceDayKey
  section: DailyMenuSectionKey
  display_order?: number | null
  is_active?: boolean
  notes?: string | null
}

export type UpdateWeeklyMenuItemPayload = {
  service_day: WeeklyMenuServiceDayKey
  section: DailyMenuSectionKey
  display_order: number
  is_active: boolean
  notes: string | null
}

export type UpsertDailyMenuAdjustmentPayload = {
  date: string
  section: DailyMenuSectionKey
  action: DailyMenuAdjustmentAction
  display_order?: number | null
  notes?: string | null
}

type BackendProductOption = {
  id: number | string
  name: string
  option_type?: string | null
  group_code?: string | null
  group_label?: string | null
  price_delta_cents?: number | null
  is_required?: boolean
  available_today?: boolean
  daily_reason?: string | null
}

type BackendProduct = {
  id: number | string
  name: string
  description?: string | null
  notes_hint?: string | null
  base_price_cents?: number | null
  is_active?: boolean
  is_available_by_default?: boolean
  product_type?: string | null
  menu_rule_code?: string | null
  category?: {
    name?: string | null
  } | null
  options?: BackendProductOption[]
}

const EMPTY_FINANCIAL_SUMMARY = {
  dateLabel: 'Hoje',
  ordersCount: 0,
  paidOrders: 0,
  pendingOrders: 0,
  grossRevenue: 0,
  confirmedRevenue: 0,
  pendingAmount: 0,
  expensesAmount: 0,
  netProfit: 0,
  pixAmount: 0,
  creditUsed: 0,
  customerCreditBalance: 0,
  averageTicket: 0,
}

export class ApiError extends Error {
  public readonly status: number
  public readonly details?: unknown

  constructor(message: string, status: number, details?: unknown) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.details = details
  }
}

export function getMockOperationalSnapshot(): OperationalSnapshot {
  return {
    orders: ordersMock,
    conversations: conversationsMock,
    customers: customersMock,
    products: productsMock.map((product) => ({
      ...product,
      options: product.options ?? [],
    })),
    deliveries: deliveryTasksMock,
    financeEntries: financeEntriesMock,
    financialSummary: dailyFinancialSummaryMock,
    expenses: expenseEntriesMock,
    paymentMethods: paymentMethodSummaryMock,
    integrations: integrationsMock,
  }
}

export async function getSession(): Promise<SessionResponse> {
  try {
    return await requestJson<SessionResponse>('/api/app/session')
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) {
      return { authenticated: false, user: null }
    }

    throw error
  }
}

export async function login(payload: LoginPayload): Promise<SessionResponse> {
  return requestJson<SessionResponse>('/api/app/login', {
    body: JSON.stringify(payload),
    method: 'POST',
  })
}

export async function logout(): Promise<void> {
  await requestJson('/api/app/logout', {
    method: 'POST',
  })

  csrfToken = null
}

export async function getOperationalSnapshot(): Promise<SnapshotResponse> {
  try {
    const response = await requestJson<ApiEnvelope<OperationalSnapshot>>('/api/app/operational-snapshot')
    const snapshot = normalizeOperationalSnapshot(response.data)

    if (snapshot.company?.slug) {
      snapshot.products = await getAvailableMenu(snapshot.company.slug, snapshot.products)
    }

    return {
      snapshot,
      source: 'api',
    }
  } catch (error) {
    if (MOCK_FALLBACK_ENABLED && !(error instanceof ApiError && error.status === 401)) {
      return {
        snapshot: getMockOperationalSnapshot(),
        source: 'mock',
        fallbackReason: error instanceof Error ? error.message : 'API indisponivel',
      }
    }

    throw error
  }
}

function normalizeOperationalSnapshot(snapshot: OperationalSnapshot | null | undefined): OperationalSnapshot {
  if (!snapshot || typeof snapshot !== 'object') {
    throw new ApiError('Payload operacional invalido.', 422, snapshot)
  }

  return {
    ...snapshot,
    orders: Array.isArray(snapshot.orders) ? snapshot.orders : [],
    conversations: Array.isArray(snapshot.conversations) ? snapshot.conversations : [],
    customers: Array.isArray(snapshot.customers) ? snapshot.customers : [],
    products: Array.isArray(snapshot.products) ? snapshot.products : [],
    deliveries: Array.isArray(snapshot.deliveries) ? snapshot.deliveries : [],
    financeEntries: Array.isArray(snapshot.financeEntries) ? snapshot.financeEntries : [],
    financialSummary: {
      ...EMPTY_FINANCIAL_SUMMARY,
      ...(snapshot.financialSummary ?? {}),
    },
    expenses: Array.isArray(snapshot.expenses) ? snapshot.expenses : [],
    paymentMethods: Array.isArray(snapshot.paymentMethods) ? snapshot.paymentMethods : [],
    integrations: Array.isArray(snapshot.integrations) ? snapshot.integrations : [],
  }
}

export async function createDraftOrder(payload: DraftOrderPayload = {}) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>('/api/app/orders/drafts', {
    body: JSON.stringify(payload),
    method: 'POST',
  })
}

export async function addOrderItem(orderId: string, payload: AddItemPayload) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>(`/api/app/orders/${orderId}/items`, {
    body: JSON.stringify(payload),
    method: 'POST',
  })
}

export async function generateTicketPreview(orderId: string) {
  return requestJson<
    ApiEnvelope<{
      order: OperationalSnapshot['orders'][number]
      preview: PrintPreviewResult
    }>
  >(`/api/app/orders/${orderId}/ticket-preview`, {
    method: 'POST',
  })
}

export async function setConversationAutomationMode(conversationId: string, payload: AutomationModePayload) {
  return requestJson<{
    id: string | number
    automation_mode: string
    automation_status: string
    human_review_required: boolean
  }>(`/api/app/conversations/${conversationId}/automation/mode`, {
    body: JSON.stringify(payload),
    method: 'POST',
  })
}

export async function updateMenuOptionAvailability(optionId: string, payload: UpdateMenuOptionAvailabilityPayload) {
  return requestJson<ApiEnvelope<MenuOption>>(`/api/app/menu/options/${optionId}/availability`, {
    body: JSON.stringify(payload),
    method: 'PATCH',
  })
}

export async function getStructuredMenuCatalog(date?: string): Promise<StructuredMenuCatalogResponse> {
  const response = await requestJson<ApiEnvelope<StructuredMenuCatalogResponse>>(
    `/api/app/menu/catalog${dateQuery(date)}`,
  )

  return response.data
}

export async function getDailyStructuredMenu(date?: string): Promise<DailyStructuredMenu> {
  const response = await requestJson<ApiEnvelope<DailyStructuredMenu>>(`/api/app/menu/day${dateQuery(date)}`)

  return response.data
}

export async function getStructuredProductConfiguration(productId: number | string, date?: string): Promise<StructuredMenuProduct> {
  const response = await requestJson<ApiEnvelope<StructuredMenuProduct>>(
    `/api/app/menu/products/${productId}/configuration${dateQuery(date)}`,
  )

  return response.data
}

export async function getAdminMenuProducts(date?: string): Promise<AdminMenuProductsResponse> {
  const response = await requestJson<ApiEnvelope<AdminMenuProductsResponse>>(
    `/api/app/menu/admin/products${dateQuery(date)}`,
  )

  return response.data
}

export async function getAdminMenuComponents(): Promise<AdminMenuComponentsResponse> {
  const response = await requestJson<ApiEnvelope<AdminMenuComponentsResponse>>('/api/app/menu/admin/components')

  return response.data
}

export async function getAdminWeeklyMenu(): Promise<AdminWeeklyMenuResponse> {
  const response = await requestJson<ApiEnvelope<AdminWeeklyMenuResponse>>('/api/app/menu/admin/weekly')

  return response.data
}

export async function getAdminDailyMenuAdjustments(date: string): Promise<AdminDailyMenuAdjustmentsResponse> {
  const response = await requestJson<ApiEnvelope<AdminDailyMenuAdjustmentsResponse>>(
    `/api/app/menu/admin/day-adjustments${dateQuery(date)}`,
  )

  return response.data
}

export async function updateMenuProduct(
  productId: number | string,
  payload: UpdateMenuProductPayload,
): Promise<StructuredMenuProduct> {
  const response = await requestJson<ApiEnvelope<StructuredMenuProduct>>(`/api/app/menu/products/${productId}`, {
    body: JSON.stringify(payload),
    method: 'PATCH',
  })

  return response.data
}

export async function createMenuComponent(payload: SaveMenuComponentPayload): Promise<AdminMenuComponent> {
  const response = await requestJson<ApiEnvelope<AdminMenuComponent>>('/api/app/menu/components', {
    body: JSON.stringify(payload),
    method: 'POST',
  })

  return response.data
}

export async function updateMenuComponent(
  componentId: number | string,
  payload: SaveMenuComponentPayload,
): Promise<AdminMenuComponent> {
  const response = await requestJson<ApiEnvelope<AdminMenuComponent>>(`/api/app/menu/components/${componentId}`, {
    body: JSON.stringify(payload),
    method: 'PATCH',
  })

  return response.data
}

export async function setComponentAvailability(
  componentId: number | string,
  payload: UpdateComponentAvailabilityPayload,
): Promise<ComponentAvailabilityMutationResponse> {
  const response = await requestJson<ApiEnvelope<ComponentAvailabilityMutationResponse>>(
    `/api/app/menu/components/${componentId}/availability`,
    {
      body: JSON.stringify(payload),
      method: 'PATCH',
    },
  )

  return response.data
}

export async function clearComponentAvailability(
  componentId: number | string,
  date: string,
): Promise<ComponentAvailabilityMutationResponse> {
  const response = await requestJson<ApiEnvelope<ComponentAvailabilityMutationResponse>>(
    `/api/app/menu/components/${componentId}/availability`,
    {
      body: JSON.stringify({ date }),
      method: 'DELETE',
    },
  )

  return response.data
}

export async function setProductComponentAvailability(
  productId: number | string,
  componentId: number | string,
  payload: Omit<UpdateComponentAvailabilityPayload, 'replacement_component_id'>,
): Promise<ComponentAvailabilityMutationResponse> {
  const response = await requestJson<ApiEnvelope<ComponentAvailabilityMutationResponse>>(
    `/api/app/menu/products/${productId}/components/${componentId}/availability`,
    {
      body: JSON.stringify(payload),
      method: 'PATCH',
    },
  )

  return response.data
}

export async function clearProductComponentAvailability(
  productId: number | string,
  componentId: number | string,
  date: string,
): Promise<ComponentAvailabilityMutationResponse> {
  const response = await requestJson<ApiEnvelope<ComponentAvailabilityMutationResponse>>(
    `/api/app/menu/products/${productId}/components/${componentId}/availability`,
    {
      body: JSON.stringify({ date }),
      method: 'DELETE',
    },
  )

  return response.data
}

export async function upsertWeeklyMenuComponent(
  componentId: number | string,
  payload: UpsertWeeklyMenuComponentPayload,
): Promise<AdminWeeklyMenuItem> {
  const response = await requestJson<ApiEnvelope<AdminWeeklyMenuItem>>(`/api/app/menu/weekly/components/${componentId}`, {
    body: JSON.stringify(payload),
    method: 'PATCH',
  })

  return response.data
}

export async function updateWeeklyMenuItem(
  itemId: number | string,
  payload: UpdateWeeklyMenuItemPayload,
): Promise<AdminWeeklyMenuItem> {
  const response = await requestJson<ApiEnvelope<AdminWeeklyMenuItem>>(`/api/app/menu/weekly-items/${itemId}`, {
    body: JSON.stringify(payload),
    method: 'PATCH',
  })

  return response.data
}

export async function deleteWeeklyMenuItem(itemId: number | string): Promise<{ cleared: boolean; id: number }> {
  const response = await requestJson<ApiEnvelope<{ cleared: boolean; id: number }>>(`/api/app/menu/weekly-items/${itemId}`, {
    method: 'DELETE',
  })

  return response.data
}

export async function upsertDailyMenuAdjustment(
  componentId: number | string,
  payload: UpsertDailyMenuAdjustmentPayload,
): Promise<DailyMenuAdjustmentMutationResponse> {
  const response = await requestJson<ApiEnvelope<DailyMenuAdjustmentMutationResponse>>(
    `/api/app/menu/day/components/${componentId}`,
    {
      body: JSON.stringify(payload),
      method: 'PATCH',
    },
  )

  return response.data
}

export async function clearDailyMenuAdjustment(
  componentId: number | string,
  date: string,
  section: DailyMenuSectionKey,
): Promise<DailyMenuAdjustmentMutationResponse> {
  const response = await requestJson<ApiEnvelope<DailyMenuAdjustmentMutationResponse>>(
    `/api/app/menu/day/components/${componentId}`,
    {
      body: JSON.stringify({ date, section }),
      method: 'DELETE',
    },
  )

  return response.data
}

async function getAvailableMenu(companySlug: string, fallbackProducts: Product[]): Promise<Product[]> {
  try {
    const response = await requestJson<ApiEnvelope<BackendProduct[]>>(`/api/restaurants/${companySlug}/menu/available`)

    return response.data.map(mapBackendProduct)
  } catch {
    return fallbackProducts
  }
}

function mapBackendProduct(product: BackendProduct): Product {
  return {
    id: String(product.id),
    category: product.category?.name ?? 'Cardapio',
    name: product.name,
    description: product.description ?? product.notes_hint ?? 'Produto cadastrado no cardapio operacional.',
    price: (product.base_price_cents ?? 0) / 100,
    available: Boolean(product.is_active ?? true) && Boolean(product.is_available_by_default ?? true),
    tags: [product.product_type, product.menu_rule_code, 'api'].filter(Boolean) as string[],
    options: product.options?.map(mapBackendOption) ?? [],
  }
}

function mapBackendOption(option: BackendProductOption): MenuOption {
  return {
    id: String(option.id),
    name: option.name,
    type: option.option_type ?? 'choice',
    groupCode: option.group_code ?? 'componentes',
    groupLabel: option.group_label ?? groupLabel(option.group_code),
    priceDelta: (option.price_delta_cents ?? 0) / 100,
    required: Boolean(option.is_required ?? false),
    availableToday: Boolean(option.available_today ?? true),
    dailyReason: option.daily_reason ?? null,
  }
}

function groupLabel(groupCode?: string | null): string {
  switch (groupCode) {
    case 'base':
    case 'bases':
    case 'guarnicoes':
      return 'Bases/guarnicoes'
    case 'salada':
      return 'Saladas'
    case 'carne':
    case 'bife':
      return 'Carnes'
    case 'bebidas':
      return 'Bebidas'
    case 'adicionais':
      return 'Adicionais'
    default:
      return 'Componentes'
  }
}

function dateQuery(date?: string): string {
  return date ? `?date=${encodeURIComponent(date)}` : ''
}

async function requestJson<T = unknown>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase()
  const headers = new Headers(init.headers)

  headers.set('Accept', 'application/json')

  if (init.body && !headers.has('Content-Type')) {
    headers.set('Content-Type', 'application/json')
  }

  if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
    headers.set('X-CSRF-TOKEN', await getCsrfToken())
  }

  const response = await fetch(`${API_BASE_URL}${path}`, {
    ...init,
    credentials: 'include',
    headers,
    method,
  })

  const contentType = response.headers.get('content-type') ?? ''
  const payload = contentType.includes('application/json') ? await response.json() : await response.text()

  if (!response.ok) {
    const message =
      typeof payload === 'object' && payload !== null && 'message' in payload
        ? String(payload.message)
        : `Erro HTTP ${response.status}`

    throw new ApiError(message, response.status, payload)
  }

  return payload as T
}

async function getCsrfToken(): Promise<string> {
  if (csrfToken) {
    return csrfToken
  }

  const response = await requestJson<{ csrf_token: string }>('/api/app/csrf-token')
  csrfToken = response.csrf_token

  return csrfToken
}
