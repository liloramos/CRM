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
import type { AuthUser, OperationalSnapshot, PrintPreviewResult, Product, SnapshotSource } from '../types/crm'

const API_BASE_URL = import.meta.env.VITE_API_BASE_URL ?? ''
const MOCK_FALLBACK_ENABLED = import.meta.env.DEV && import.meta.env.VITE_DISABLE_MOCK_FALLBACK !== 'true'

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
    products: productsMock,
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
    const snapshot = response.data

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
  }
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
