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
  AccountSecurity,
  AiAutomationSandboxResult,
  AiAutomationSettings,
  AuthUser,
  BackendOrderStatus,
  ComponentAvailabilityMutationResponse,
  CounterSaleDraft,
  CounterSaleProduct,
  CounterSaleDetail,
  CounterSaleHistory,
  CounterSaleHistoryFilters,
  Conversation,
  ConversationAiStyle,
  ConversationAlert,
  OperationalNotification,
  ConversationQuickReply,
  ConversationQuickReplyCategory,
  CustomerSummary,
  DailyMenuAdjustmentMutationResponse,
  DailyMenuAdjustmentAction,
  DailyMenuComponent,
  DailyMenuSectionKey,
  DailyStructuredMenu,
  DeliveryAddress,
  DeliveryCoordinates,
  DeliveryDistanceBand,
  DeliveryMapTask,
  DeliverySettings,
  EffectiveAvailabilityStatus,
  FinancialOverview,
  MenuOption,
  MenuComponentTypeKey,
  OperationalSnapshot,
  OperationalReport,
  PaymentSettings,
  PrintPreviewResult,
  Product,
  ProductServiceDayKey,
  ResolvedProductConfiguration,
  SnapshotSource,
  StructuredMenuCatalogResponse,
  StructuredMenuCategorySummary,
  SystemAssistantResponse,
  SupportTicket,
  SupportTicketCenter,
  StructuredMenuProduct,
  WeeklyMenuServiceDayKey,
  WhatsAppIntegration,
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
  payer_customer_id?: string | null
  customer_name_snapshot?: string | null
  customer_phone_snapshot?: string | null
  fulfillment_type?: 'pickup' | 'delivery' | 'counter'
  general_notes?: string
  kitchen_notes?: string
  pickup_person_name?: string
  seller_user_id?: string | null
}

export type AddItemPayload = {
  product_id: string
  quantity: number
  item_notes?: string
  beneficiary_name?: string | null
  options?: Array<{
    product_option_id: string
    quantity?: number
  }>
  structured_options?: Array<{
    component_link_id?: number
    product_link_id?: number
    quantity?: number
  }>
  included_component_ids?: number[]
  removed_component_ids?: number[]
  removed_group_codes?: string[]
  meat_mode?: 'traditional' | 'beef_only' | 'none'
  traditional_meat_component_ids?: number[]
  additions?: Array<{
    code: string
    quantity: number
  }>
}

type CreateCustomerPayload = {
  name: string
  phone?: string
  email?: string
  notes?: string
  address?: CustomerAddressPayload
}

export type CustomerAddressPayload = {
  street?: string
  number?: string
  complement?: string
  neighborhood?: string
  city?: string
  reference?: string
}

export type UpdateCustomerPayload = {
  name: string
  phone?: string
  email?: string
  notes?: string
  address?: CustomerAddressPayload
}

type CancelOrderPayload = {
  reason: string
  notes?: string
}

type UpdateOrderStatusPayload = {
  status: BackendOrderStatus
  reason?: string
  notes?: string
}

type ConfirmOrderPaymentPayload = {
  method: 'pix' | 'cash' | 'debit_card' | 'credit_card' | 'customer_credit' | 'other'
  amount_cents?: number
  notes?: string
}

export type OrderDeletionResponse = {
  deleted: number
  order_ids: string[]
  eligible?: Array<{ order_id: string; code?: string | null }>
  blocked?: Array<{ order_id: string; code?: string | null; reasons: string[] }>
}

type UpdateMenuOptionAvailabilityPayload = {
  status: 'available' | 'unavailable'
  reason?: string
  date?: string
}

type AutomationModePayload = {
  mode: 'assisted' | 'automatic' | 'manual'
  reason?: string
}

type ConversationListParams = {
  search?: string
  mode?: 'all' | 'automatic' | 'manual' | 'attention' | 'unread' | 'alerts'
  since?: string | null
}

export type ConversationListResponse = {
  conversations: Conversation[]
  alerts: ConversationAlert[]
  notifications: OperationalNotification[]
  generatedAt: string | null
}

export type ApproveConversationPaymentPayload = {
  confirmed_amount_cents: number
  notes?: string
}

export type RejectConversationPaymentPayload = {
  reason: string
}

export type UpdateMenuProductPayload = {
  date?: string
  name: string
  description: string | null
  price_cents: number
  is_active: boolean
  is_available_by_default: boolean
  display_order: number
  category_id?: number
  service_days: ProductServiceDayKey[]
  beef_rules?: {
    beef_only: {
      enabled: boolean
      final_price_cents: number | null
    }
    extra_beef: {
      enabled: boolean
      price_cents: number | null
      max_quantity: number | null
    }
  }
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

export type UpdateProductComponentOptionPayload = {
  date?: string
  resolution: 'offered' | 'not_offered'
  final_price_cents?: number | null
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
  public readonly code?: string

  constructor(message: string, status: number, details?: unknown, code?: string) {
    super(message)
    this.name = 'ApiError'
    this.status = status
    this.details = details
    this.code = code
  }
}

export type CreateMenuProductPayload = {
  date?: string
  name: string
  description: string | null
  price_cents: number
  is_active: boolean
  is_available_by_default: boolean
  category_id: number
  is_counter_product: boolean
  service_days: ProductServiceDayKey[]
}

export type SaveMenuCategoryPayload = {
  name: string
  description: string | null
  display_order: number
  is_active: boolean
}

export type DeleteMenuProductResult = {
  outcome: 'deleted' | 'archived'
  message: string
  product: StructuredMenuProduct | null
}

export function describeApiError(error: unknown, fallback: string): string {
  if (error instanceof ApiError) {
    if (error.status === 401) {
      return 'Sua sessão expirou. Entre novamente.'
    }

    if (error.status === 403) {
      return 'Seu usuário não tem permissão para realizar esta ação.'
    }

    if (error.status === 404) {
      return 'O endpoint solicitado não está disponível no backend em execução.'
    }

    if (error.status === 422 || error.code?.startsWith('whatsapp_')) {
      return error.message
    }

    if (error.status >= 500) {
      return 'O backend não conseguiu concluir a solicitação.'
    }

    return error.message || fallback
  }

  if (error instanceof TypeError) {
    return 'Não foi possível conectar ao backend. Confirme se o servidor está em execução.'
  }

  return error instanceof Error && error.message ? error.message : fallback
}

export function getMockOperationalSnapshot(): OperationalSnapshot {
  return {
    capabilities: {
      can_permanently_delete_orders: false,
      can_run_destructive_test_cleanup: false,
      destructive_cleanup_environment: 'mock',
    },
    orders: ordersMock,
    sellerCandidates: [],
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

    snapshot.products = await getStructuredOperationalProducts()

    return {
      snapshot,
      source: 'api',
    }
  } catch (error) {
    if (MOCK_FALLBACK_ENABLED && !(error instanceof ApiError && error.status === 401)) {
      return {
        snapshot: getMockOperationalSnapshot(),
        source: 'mock',
        fallbackReason: error instanceof Error ? error.message : 'API indisponível',
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
    capabilities: {
      can_permanently_delete_orders: Boolean(snapshot.capabilities?.can_permanently_delete_orders ?? false),
      can_run_destructive_test_cleanup: Boolean(snapshot.capabilities?.can_run_destructive_test_cleanup ?? false),
      destructive_cleanup_environment: snapshot.capabilities?.destructive_cleanup_environment ?? 'unknown',
    },
    orders: Array.isArray(snapshot.orders) ? snapshot.orders : [],
    sellerCandidates: Array.isArray(snapshot.sellerCandidates) ? snapshot.sellerCandidates : [],
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

export type CompleteCounterSalePayload = {
  items: Array<{
    product_id: number
    quantity: number
    weight_grams?: number
    selected_components?: string[]
    additions?: Array<{
      code: 'extra_beef'
      quantity: 1
    }>
  }>
  payment_method: 'pix' | 'cash' | 'debit_card' | 'credit_card'
  seller_user_id?: number | null
} & CounterSaleCustomerPayload

export type CounterSaleCustomerPayload = {
  customer_id?: number | null
  customer_name?: string | null
  customer_phone?: string | null
  save_customer?: boolean
}

export type CounterSaleDraftPayload = CounterSaleCustomerPayload & {
  product_id: number
  selected_components?: string[]
  additions?: Array<{ code: 'extra_beef'; quantity: 1 }>
  notes?: string
  seller_user_id?: number | null
}

export type FinalizeCounterSaleDraftPayload = CounterSaleCustomerPayload & {
  weight_grams?: number
  selected_components?: string[]
  additions?: Array<{ code: 'extra_beef'; quantity: 1 }>
  notes?: string
  payment_method: 'pix' | 'cash' | 'debit_card' | 'credit_card'
  seller_user_id?: number | null
}

export async function getCounterSaleProducts(): Promise<CounterSaleProduct[]> {
  const response = await requestJson<ApiEnvelope<CounterSaleProduct[]>>('/api/app/counter-sales/products')

  return response.data
}

export async function getCounterSaleHistory(filters: CounterSaleHistoryFilters = {}): Promise<CounterSaleHistory> {
  const params = new URLSearchParams()

  if (filters.dateFrom) params.set('date_from', filters.dateFrom)
  if (filters.dateTo) params.set('date_to', filters.dateTo)
  if (filters.paymentMethod) params.set('payment_method', filters.paymentMethod)
  if (filters.status) params.set('status', filters.status)

  const query = params.toString()
  const response = await requestJson<ApiEnvelope<CounterSaleHistory>>(`/api/app/counter-sales${query ? `?${query}` : ''}`)

  return response.data
}

export async function getCounterSaleDetail(orderId: string): Promise<CounterSaleDetail> {
  const response = await requestJson<ApiEnvelope<CounterSaleDetail>>(`/api/app/counter-sales/${orderId}`)

  return response.data
}

export async function completeCounterSale(payload: CompleteCounterSalePayload) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>('/api/app/counter-sales', {
    body: JSON.stringify(payload),
    method: 'POST',
  })
}

export async function cancelCounterSale(orderId: string, payload: CancelOrderPayload) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>(`/api/app/counter-sales/${orderId}/cancel`, {
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

export async function deleteDraftOrder(orderId: string) {
  return requestJson<ApiEnvelope<{ deleted: boolean; id: string }>>(`/api/app/orders/${orderId}`, {
    method: 'DELETE',
  })
}

export async function deleteOrderPermanently(orderId: string, confirmation: string) {
  return requestJson<ApiEnvelope<OrderDeletionResponse>>(`/api/app/orders/${orderId}/permanent`, {
    body: JSON.stringify({ confirmation }),
    method: 'DELETE',
  })
}

export async function deleteOrdersPermanently(orderIds: string[], confirmation: string) {
  return requestJson<ApiEnvelope<OrderDeletionResponse>>('/api/app/orders/permanent-deletion', {
    body: JSON.stringify({ order_ids: orderIds, confirmation }),
    method: 'POST',
  })
}

export async function cleanupTestOrders(orderIds: string[], confirmation: string) {
  return requestJson<ApiEnvelope<OrderDeletionResponse>>('/api/app/orders/test-cleanup', {
    body: JSON.stringify({ order_ids: orderIds, confirmation }),
    method: 'POST',
  })
}

export async function searchCustomers(search: string, limit = 12): Promise<CustomerSummary[]> {
  const params = new URLSearchParams()

  if (search.trim()) {
    params.set('search', search.trim())
  }

  params.set('limit', String(limit))

  const response = await requestJson<ApiEnvelope<CustomerSummary[]>>(`/api/app/customers?${params.toString()}`)

  return response.data
}

export async function createCustomer(payload: CreateCustomerPayload): Promise<CustomerSummary> {
  const response = await requestJson<ApiEnvelope<CustomerSummary>>('/api/app/customers', {
    body: JSON.stringify(payload),
    method: 'POST',
  })

  return response.data
}

export async function getCounterSaleDrafts(): Promise<CounterSaleDraft[]> {
  const response = await requestJson<ApiEnvelope<CounterSaleDraft[]>>('/api/app/counter-sales/drafts')

  return response.data
}

export async function createCounterSaleDraft(payload: CounterSaleDraftPayload) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>('/api/app/counter-sales/drafts', {
    body: JSON.stringify(payload),
    method: 'POST',
  })
}

export async function finalizeCounterSaleDraft(orderId: string, payload: FinalizeCounterSaleDraftPayload) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>(`/api/app/counter-sales/${orderId}/finalize`, {
    body: JSON.stringify(payload),
    method: 'POST',
  })
}

export async function updateCounterSaleDraftCustomer(orderId: string, customer: CounterSaleCustomerPayload): Promise<CounterSaleDraft> {
  const response = await requestJson<ApiEnvelope<CounterSaleDraft>>(`/api/app/counter-sales/${orderId}/customer`, {
    body: JSON.stringify(customer),
    method: 'PATCH',
  })

  return response.data
}

export type AccountProfile = { name: string; email: string; phone: string | null; jobTitle: string | null; avatarUrl: string | null; companyName: string | null; roles: string[]; permissions: string[] }
export type AccountCompany = { name: string; displayName: string | null; legalName: string | null; responsibleName: string | null; contactEmail: string | null; contactPhone: string | null; timezone: string | null; addressLine: string | null; addressNumber: string | null; addressComplement: string | null; district: string | null; city: string | null; state: string | null; postalCode: string | null; logoUrl: string | null }
export async function getAccountProfile(): Promise<AccountProfile> { return (await requestJson<ApiEnvelope<AccountProfile>>('/api/app/account/profile')).data }
export async function saveAccountProfile(data: Record<string, string>): Promise<AccountProfile> {
  return (await requestJson<ApiEnvelope<AccountProfile>>('/api/app/account/profile', {
    method: 'PATCH',
    body: JSON.stringify({
      name: data.name,
      email: data.email,
      phone: data.phone,
      job_title: data.jobTitle,
      current_password: data.current_password || undefined,
      password: data.password || undefined,
      password_confirmation: data.password_confirmation || undefined,
    }),
  })).data
}
export async function uploadAccountAvatar(file: File): Promise<AccountProfile> { const body = new FormData(); body.append('avatar', file); return (await requestJson<ApiEnvelope<AccountProfile>>('/api/app/account/profile/avatar', { method: 'POST', body })).data }
export async function removeAccountAvatar(): Promise<AccountProfile> { return (await requestJson<ApiEnvelope<AccountProfile>>('/api/app/account/profile/avatar', { method: 'DELETE' })).data }
export async function getAccountCompany(): Promise<AccountCompany> { return (await requestJson<ApiEnvelope<AccountCompany>>('/api/app/account/company')).data }
export type GeneralSettings = {
  timezone: string
  operating_hours: Array<{ weekday: number; is_open: boolean; opens_at: string | null; closes_at: string | null; notes: string | null }>
  operating_exceptions: Array<{ date: string; is_open: boolean; opens_at: string | null; closes_at: string | null; notes: string | null }>
}
export async function getGeneralSettings(): Promise<GeneralSettings> { return (await requestJson<ApiEnvelope<GeneralSettings>>('/api/app/settings/general')).data }
export async function saveGeneralSettings(data: GeneralSettings): Promise<GeneralSettings> { return (await requestJson<ApiEnvelope<GeneralSettings>>('/api/app/settings/general', { method: 'PATCH', body: JSON.stringify(data) })).data }
export type PrintSettings = { printer: { id: number; name: string; paper_width_mm: number; print_mode: string; status: string } | null; templates: Array<{ id: number; name: string; is_default: boolean; width_chars: number; includes_financials: boolean }>; latest_job: { id: number; order_code: string | null; status: string; requested_at: string | null } | null; recent_failures_count: number }
export async function getPrintSettings(): Promise<PrintSettings> { return (await requestJson<ApiEnvelope<PrintSettings>>('/api/app/settings/printing')).data }
export async function savePrintSettings(data: { printer_name: string; paper_width_mm: number; receipt_template_id: number | null }): Promise<PrintSettings> { return (await requestJson<ApiEnvelope<PrintSettings>>('/api/app/settings/printing', { method: 'PATCH', body: JSON.stringify(data) })).data }
export async function getPaymentSettings(): Promise<PaymentSettings> { return (await requestJson<ApiEnvelope<PaymentSettings>>('/api/app/settings/payments')).data }
export async function savePaymentSettings(data: PaymentSettings): Promise<PaymentSettings> { return (await requestJson<ApiEnvelope<PaymentSettings>>('/api/app/settings/payments', { method: 'PATCH', body: JSON.stringify({ methods: Object.fromEntries(data.methods.map((method) => [method.code, method.enabled])), pix: data.pix }) })).data }
export async function getAccountSecurity(): Promise<AccountSecurity> { return (await requestJson<ApiEnvelope<AccountSecurity>>('/api/app/account/security')).data }
export async function getWhatsAppIntegration(): Promise<WhatsAppIntegration> { return (await requestJson<ApiEnvelope<WhatsAppIntegration>>('/api/app/integrations/whatsapp')).data }
export async function checkWhatsAppIntegration(): Promise<{ status: 'validated' | 'failed'; message: string; checked_at: string }> { return (await requestJson<ApiEnvelope<{ status: 'validated' | 'failed'; message: string; checked_at: string }>>('/api/app/integrations/whatsapp/check', { method: 'POST' })).data }
export async function getAiAutomationSettings(): Promise<AiAutomationSettings> { return (await requestJson<ApiEnvelope<AiAutomationSettings>>('/api/app/automation/ai')).data }
export async function saveAiAutomationRollout(rollout: AiAutomationSettings['rollout']): Promise<AiAutomationSettings> { return (await requestJson<ApiEnvelope<AiAutomationSettings>>('/api/app/automation/ai', { method: 'PATCH', body: JSON.stringify({ rollout }) })).data }
export async function saveAiAutomationGuidance(instructions: string[]): Promise<AiAutomationSettings> { return (await requestJson<ApiEnvelope<AiAutomationSettings>>('/api/app/automation/ai/guidance', { method: 'PATCH', body: JSON.stringify({ instructions }) })).data }
export async function runAiAutomationSandbox(message: string): Promise<AiAutomationSandboxResult> { return (await requestJson<ApiEnvelope<AiAutomationSandboxResult>>('/api/app/automation/ai/sandbox', { method: 'POST', body: JSON.stringify({ message }) })).data }
export async function askSystemAssistant(message: string, currentRoute?: string, recentHistory: Array<{ role: 'user' | 'assistant'; text: string }> = []): Promise<SystemAssistantResponse> { return (await requestJson<ApiEnvelope<SystemAssistantResponse>>('/api/app/assistant', { method: 'POST', body: JSON.stringify({ message, current_route: currentRoute, recent_history: recentHistory }) })).data }
export async function getSupportTickets(): Promise<SupportTicketCenter> { return (await requestJson<ApiEnvelope<SupportTicketCenter>>('/api/app/support/tickets')).data }
export async function createSupportTicket(data: { category: SupportTicket['category']; subject: string; description: string; priority: SupportTicket['priority']; current_route?: string }): Promise<SupportTicket> { return (await requestJson<ApiEnvelope<SupportTicket>>('/api/app/support/tickets', { method: 'POST', body: JSON.stringify(data) })).data }
export async function confirmSecurityPassword(password: string): Promise<void> { await requestJson('/user/confirm-password', { method: 'POST', body: JSON.stringify({ password }) }) }
export async function enableTwoFactor(): Promise<void> { await requestJson('/user/two-factor-authentication', { method: 'POST' }) }
export async function disableTwoFactor(): Promise<void> { await requestJson('/user/two-factor-authentication', { method: 'DELETE' }) }
export async function getTwoFactorQrCode(): Promise<{ svg: string }> { return requestJson<{ svg: string }>('/user/two-factor-qr-code') }
export async function confirmTwoFactor(code: string): Promise<void> { await requestJson('/user/confirmed-two-factor-authentication', { method: 'POST', body: JSON.stringify({ code }) }) }
export async function getTwoFactorRecoveryCodes(): Promise<string[]> { return requestJson<string[]>('/user/two-factor-recovery-codes') }
export async function regenerateTwoFactorRecoveryCodes(): Promise<string[]> { return requestJson<string[]>('/user/two-factor-recovery-codes', { method: 'POST' }) }
export type ManagedUser = { id: string; name: string; email: string; phone: string | null; jobTitle: string | null; avatarUrl: string | null; canBeSeller: boolean; isActive: boolean; roles: string[]; permissions: string[]; companyName: string | null; canEdit: boolean; canEditAccess: boolean }
export type UserManagementData = {
  users: ManagedUser[]
  available_roles: Array<{ name: string; label: string; permissions: string[]; protected: boolean }>
  available_permissions: Array<{ name: string; label: string }>
}
export type ManagedUserPayload = {
  name: string
  email: string
  phone: string
  job_title: string
  role: string
  permissions: string[]
  can_be_seller: boolean
  is_active: boolean
  password?: string
  password_confirmation?: string
}
export async function getManagedUsers(): Promise<UserManagementData> { return (await requestJson<ApiEnvelope<UserManagementData>>('/api/app/settings/users')).data }
export async function createManagedUser(data: ManagedUserPayload): Promise<ManagedUser> { return (await requestJson<ApiEnvelope<ManagedUser>>('/api/app/settings/users', { method: 'POST', body: JSON.stringify(data) })).data }
export async function updateManagedUser(id: string, data: ManagedUserPayload): Promise<ManagedUser> { return (await requestJson<ApiEnvelope<ManagedUser>>(`/api/app/settings/users/${id}`, { method: 'PATCH', body: JSON.stringify(data) })).data }
export async function uploadManagedUserAvatar(id: string, file: File): Promise<ManagedUser> { const body = new FormData(); body.append('avatar', file); return (await requestJson<ApiEnvelope<ManagedUser>>(`/api/app/settings/users/${id}/avatar`, { method: 'POST', body })).data }
export async function removeManagedUserAvatar(id: string): Promise<ManagedUser> { return (await requestJson<ApiEnvelope<ManagedUser>>(`/api/app/settings/users/${id}/avatar`, { method: 'DELETE' })).data }
export async function saveAccountCompany(data: Record<string, string>): Promise<AccountCompany> { return (await requestJson<ApiEnvelope<AccountCompany>>('/api/app/account/company', { method: 'PATCH', body: JSON.stringify(data) })).data }
export async function uploadAccountCompanyLogo(file: File): Promise<AccountCompany> { const body = new FormData(); body.append('logo', file); return (await requestJson<ApiEnvelope<AccountCompany>>('/api/app/account/company/logo', { method: 'POST', body })).data }

export async function deleteCustomer(customerId: string): Promise<void> {
  await requestJson<ApiEnvelope<{ deleted: boolean; customer_id: string }>>(`/api/app/customers/${customerId}`, {
    method: 'DELETE',
  })
}

export type FinancialOverviewFilters = {
  from?: string
  to?: string
  method?: string
  status?: string
  search?: string
}

export async function getFinancialOverview(filters: FinancialOverviewFilters = {}): Promise<FinancialOverview> {
  const params = new URLSearchParams()
  Object.entries(filters).forEach(([key, value]) => {
    if (value) params.set(key, value)
  })
  const query = params.toString()
  const response = await requestJson<ApiEnvelope<FinancialOverview>>(`/api/app/financial-overview${query ? `?${query}` : ''}`)

  return response.data
}

export async function getOperationalReport(from?: string, to?: string): Promise<OperationalReport> {
  const params = new URLSearchParams()
  if (from) params.set('from', from)
  if (to) params.set('to', to)
  const query = params.toString()
  const response = await requestJson<ApiEnvelope<OperationalReport>>(`/api/app/reports/operational${query ? `?${query}` : ''}`)

  return response.data
}

export async function cancelOrder(orderId: string, payload: CancelOrderPayload) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>(`/api/app/orders/${orderId}/cancel`, {
    body: JSON.stringify(payload),
    method: 'POST',
  })
}

export async function updateOrderStatus(orderId: string, payload: UpdateOrderStatusPayload) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>(`/api/app/orders/${orderId}/status`, {
    body: JSON.stringify(payload),
    method: 'PATCH',
  })
}

export async function confirmOrderPayment(orderId: string, payload: ConfirmOrderPaymentPayload) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>(`/api/app/orders/${orderId}/payments/confirm`, {
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

export async function startOrderPrint(orderId: string) {
  return requestJson<
    ApiEnvelope<{
      order: OperationalSnapshot['orders'][number]
      preview: PrintPreviewResult
    }>
  >(`/api/app/orders/${orderId}/print/start`, {
    method: 'POST',
  })
}

export async function confirmOrderPrint(orderId: string) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>(`/api/app/orders/${orderId}/print/confirm`, {
    method: 'POST',
  })
}

export function getOrderTicketPreviewUrl(
  orderId: string,
  autoprint = false,
  targetAudience: 'kitchen' | 'cashier' | 'delivery' = 'kitchen',
  printJobId?: string,
): string {
  const path = `/orders/${encodeURIComponent(orderId.trim())}/ticket/preview`
  const params = new URLSearchParams({ target_audience: targetAudience })
  if (autoprint) params.set('autoprint', '1')
  if (printJobId) params.set('print_job_id', printJobId)

  return `${API_BASE_URL}${path}?${params.toString()}`
}

export async function updateOrderSeller(orderId: string, sellerUserId: string | null) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>(`/api/app/orders/${orderId}/seller`, {
    body: JSON.stringify({ seller_user_id: sellerUserId }),
    method: 'PATCH',
  })
}

export async function setConversationAutomationMode(conversationId: string, payload: AutomationModePayload) {
  const response = await requestJson<ApiEnvelope<Conversation>>(`/api/app/conversations/${conversationId}/mode`, {
    body: JSON.stringify(payload),
    method: 'POST',
  })

  return response.data
}

export async function updateOrderItem(orderId: string, itemId: string, payload: AddItemPayload) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>(`/api/app/orders/${orderId}/items/${itemId}`, {
    body: JSON.stringify(payload),
    method: 'PATCH',
  })
}

export async function removeOrderItem(orderId: string, itemId: string) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>(`/api/app/orders/${orderId}/items/${itemId}`, {
    method: 'DELETE',
  })
}

export type CopilotAnalysis = {
  schema_version: number
  intent: string
  confidence: number
  summary: string
  draft_order: { items: Array<{ menu_item_id: number | null; menu_item_slug: string; quantity: number; selections?: Record<string, unknown>; removed_components: string[]; item_notes: string; valid?: boolean }>; fulfillment: 'delivery' | 'pickup' | null; address: string; payment_method: string }
  missing_information: Array<{ code: string; label: string; message?: string }>
  warnings: Array<{ code: string; message: string }>
  suggested_reply: string
  metadata?: Record<string, unknown>
  clarification?: { type: 'PRODUCT'; prompt: string; options: Array<{ menu_item_id: number; menu_item_slug: string; display_name: string }>; source: 'MENU'; grounded: true } | null
  requires_human_review: boolean
  proposal?: CopilotOrderProposal
}

export type CopilotOrderProposal = {
  source: 'safe_result'
  intent: string
  applyability: 'READY' | 'PARTIAL' | 'BLOCKED'
  can_apply: boolean
  blocking_reasons: string[]
  items: Array<{
    menu_item_id: number
    menu_item_slug: string
    product_name: string
    quantity: number
    selections: Record<string, unknown>
    removed_components: string[]
    item_notes: string
    applyability: 'READY' | 'PARTIAL'
    missing_information: Array<{ code: string; label: string; message?: string }>
    warnings: Array<{ code: string; message: string }>
    operation: 'ADD_ITEM'
  }>
  target: {
    state: 'NEW_ORDER' | 'ACTIVE_ORDER' | 'UNRESOLVED' | 'UNAVAILABLE'
    requires_human_selection: boolean
    choices: Array<'NEW_ORDER' | 'ACTIVE_ORDER'>
    default_choice: 'NEW_ORDER' | 'ACTIVE_ORDER' | null
    active_order: { id: string; code: string; company_id: number; customer_id: string | null } | null
  }
  fulfillment: 'delivery' | 'pickup' | null
  delivery_address: string
  payment_method: string
  missing_information: Array<{ code: string; label: string; message?: string }>
  warnings: Array<{ code: string; message: string }>
  requires_human_review: true
}

export async function analyzeConversationCopilot(conversationId: string) {
  return requestJson<ApiEnvelope<CopilotAnalysis>>(`/api/app/conversations/${conversationId}/copilot/analyze`, { method: 'POST' })
}

export async function voidOrderPayment(orderId: string, reason: string, paymentId?: string) {
  const path = paymentId
    ? `/api/app/orders/${orderId}/payments/${paymentId}/void`
    : `/api/app/orders/${orderId}/payments/void`

  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]>>(path, {
    body: JSON.stringify({ reason }),
    method: 'POST',
  })
}

export type FulfillmentAction = 'ready' | 'start-delivery' | 'delivered' | 'picked-up'

export async function advanceOrderFulfillment(orderId: string, action: FulfillmentAction, sendCustomerNotification = false) {
  return requestJson<ApiEnvelope<OperationalSnapshot['orders'][number]> & { warning?: string | null }>(`/api/app/orders/${orderId}/fulfillment/${action}`, {
    body: JSON.stringify({ send_customer_notification: sendCustomerNotification }),
    method: 'POST',
  })
}

export async function getDeliveryTasks(): Promise<DeliveryMapTask[]> {
  const response = await requestJson<ApiEnvelope<DeliveryMapTask[]>>('/api/app/deliveries')

  return response.data
}

export async function getDeliverySettings(): Promise<DeliverySettings> {
  const response = await requestJson<ApiEnvelope<DeliverySettings>>('/api/app/delivery-settings')

  return response.data
}

export type UpdateDeliverySettingsPayload = {
  is_active?: boolean
  maps_provider?: 'google' | 'fake' | 'none'
  pricing_mode: 'per_km' | 'distance_bands'
  rate_per_km_cents?: number
  minimum_fee_cents?: number | null
  maximum_distance_km?: number | null
  origin: { address?: string; latitude: number; longitude: number }
  distance_bands?: DeliveryDistanceBand[]
}

export async function updateDeliverySettings(payload: UpdateDeliverySettingsPayload): Promise<DeliverySettings> {
  const response = await requestJson<ApiEnvelope<DeliverySettings>>('/api/app/delivery-settings', {
    body: JSON.stringify(payload),
    method: 'PATCH',
  })

  return response.data
}

export async function recalculateDeliveryRoute(orderId: string) {
  return requestJson<ApiEnvelope<unknown>>(`/api/app/orders/${orderId}/delivery/recalculate`, { method: 'POST' })
}

export async function setDeliveryCoordinates(orderId: string, coordinates: DeliveryCoordinates) {
  return requestJson<ApiEnvelope<unknown>>(`/api/app/orders/${orderId}/delivery/coordinates`, {
    body: JSON.stringify(coordinates),
    method: 'POST',
  })
}

export async function geocodeDeliveryAddress(orderId: string, address: string) {
  return requestJson<ApiEnvelope<unknown>>(`/api/app/orders/${orderId}/delivery/geocode`, {
    body: JSON.stringify({ address }),
    method: 'POST',
  })
}

export type UpdateDeliveryAddressPayload = Pick<DeliveryAddress, 'postal_code' | 'street' | 'number' | 'complement' | 'neighborhood' | 'city' | 'state' | 'reference'>

export async function updateDeliveryAddress(orderId: string, payload: UpdateDeliveryAddressPayload): Promise<{ task: DeliveryMapTask; warning: string | null }> {
  const response = await requestJson<ApiEnvelope<DeliveryMapTask> & { warning?: string | null }>(`/api/app/orders/${orderId}/delivery/address`, {
    body: JSON.stringify(payload),
    method: 'PATCH',
  })

  return { task: response.data, warning: response.warning ?? null }
}

export async function overrideDeliveryFee(orderId: string, finalFeeCents: number, reason?: string) {
  return requestJson<ApiEnvelope<unknown>>(`/api/app/orders/${orderId}/delivery/fee-override`, {
    body: JSON.stringify({ final_fee_cents: finalFeeCents, reason }),
    method: 'POST',
  })
}

export async function createOrderFromConversation(conversationId: string) {
  return requestJson<ApiEnvelope<{ order: OperationalSnapshot['orders'][number]; conversation: Conversation }>>(
    `/api/app/conversations/${conversationId}/orders`,
    { method: 'POST' },
  )
}

export async function updateCustomer(customerId: string, payload: UpdateCustomerPayload): Promise<CustomerSummary> {
  const response = await requestJson<ApiEnvelope<CustomerSummary>>(`/api/app/customers/${customerId}`, {
    body: JSON.stringify(payload),
    method: 'PATCH',
  })

  return response.data
}

export async function getConversations(params: ConversationListParams = {}): Promise<ConversationListResponse> {
  const searchParams = new URLSearchParams()

  if (params.search?.trim()) {
    searchParams.set('search', params.search.trim())
  }

  if (params.mode && params.mode !== 'all') {
    searchParams.set('mode', params.mode)
  }

  if (params.since) {
    searchParams.set('since', params.since)
  }

  const query = searchParams.toString()
  const response = await requestJson<ApiEnvelope<{ conversations: Conversation[]; alerts: ConversationAlert[]; notifications?: OperationalNotification[] }>>(
    `/api/app/conversations${query ? `?${query}` : ''}`,
  )

  return {
    conversations: response.data.conversations,
    alerts: response.data.alerts,
    notifications: response.data.notifications ?? [],
    generatedAt: typeof response.meta?.generated_at === 'string' ? response.meta.generated_at : null,
  }
}

export async function getConversation(conversationId: string): Promise<Conversation> {
  const response = await requestJson<ApiEnvelope<Conversation>>(`/api/app/conversations/${conversationId}`)

  return response.data
}

export async function markConversationAsRead(conversationId: string): Promise<Conversation> {
  const response = await requestJson<ApiEnvelope<Conversation>>('/api/app/conversations/' + conversationId + '/read', {
    method: 'POST',
  })

  return response.data
}

export async function toggleConversationMessagePin(conversationId: string, messageId: string): Promise<Conversation> {
  const response = await requestJson<ApiEnvelope<Conversation>>(
    `/api/app/conversations/${conversationId}/messages/${messageId}/pin`,
    { method: 'POST' },
  )

  return response.data
}

export async function hideConversationMessage(conversationId: string, messageId: string): Promise<Conversation> {
  const response = await requestJson<ApiEnvelope<Conversation>>(
    `/api/app/conversations/${conversationId}/messages/${messageId}/hide`, { method: 'POST' },
  )
  return response.data
}

export async function toggleConversationPin(conversationId: string): Promise<Conversation> {
  const response = await requestJson<ApiEnvelope<Conversation>>(`/api/app/conversations/${conversationId}/pin`, { method: 'POST' })
  return response.data
}

export async function getConversationStickerFavorites(): Promise<string[]> {
  const response = await requestJson<ApiEnvelope<string[]>>('/api/app/conversations/sticker-favorites')
  return response.data
}

export async function toggleConversationStickerFavorite(contentHash: string, mediaId: string): Promise<boolean> {
  const response = await requestJson<ApiEnvelope<{ favorited: boolean }>>('/api/app/conversations/sticker-favorites/toggle', {
    body: JSON.stringify({ content_hash: contentHash, media_id: Number(mediaId) }), method: 'POST',
  })
  return response.data.favorited
}

export async function sendConversationMessage(
  conversationId: string,
  body: string,
  clientReference?: string,
  replyToMessageId?: string,
): Promise<Conversation> {
  const response = await requestJson<ApiEnvelope<Conversation>>(`/api/app/conversations/${conversationId}/messages`, {
    body: JSON.stringify({
      body,
      client_reference: clientReference,
      reply_to_message_id: replyToMessageId,
    }),
    method: 'POST',
  })

  return response.data
}

export type ConversationMediaSendOptions = {
  recordingSource?: 'browser'
  recordingMimeType?: string
  recordingRequestedMimeType?: string
}

export async function sendConversationMedia(conversationId: string, file: File, mediaType: 'image' | 'video' | 'document' | 'audio' | 'sticker', caption = '', options: ConversationMediaSendOptions = {}): Promise<Conversation> {
  const form = new FormData()
  form.append('file', file)
  form.append('media_type', mediaType)
  form.append('caption', caption)
  if (options.recordingSource) form.append('recording_source', options.recordingSource)
  if (options.recordingMimeType) form.append('recording_mime_type', options.recordingMimeType)
  if (options.recordingRequestedMimeType) form.append('recording_requested_mime_type', options.recordingRequestedMimeType)
  const response = await requestJson<ApiEnvelope<Conversation>>(`/api/app/conversations/${conversationId}/media`, {
    body: form,
    method: 'POST',
  })
  return response.data
}

export async function reactToConversationMessage(conversationId: string, messageId: string, emoji: string): Promise<Conversation> {
  const response = await requestJson<ApiEnvelope<Conversation>>(`/api/app/conversations/${conversationId}/messages/${messageId}/reaction`, {
    body: JSON.stringify({ emoji }),
    method: 'POST',
  })

  return response.data
}

export type ConversationQuickReplyPayload = {
  title: string
  shortcut: string
  body: string
  category: ConversationQuickReplyCategory
  is_active: boolean
  display_order: number
}

export async function getConversationQuickReplies(includeInactive = false): Promise<ConversationQuickReply[]> {
  const query = includeInactive ? '?include_inactive=1' : ''
  const response = await requestJson<ApiEnvelope<ConversationQuickReply[]>>(`/api/app/conversation-quick-replies${query}`)

  return response.data
}

export async function createConversationQuickReply(
  payload: ConversationQuickReplyPayload,
): Promise<ConversationQuickReply> {
  const response = await requestJson<ApiEnvelope<ConversationQuickReply>>('/api/app/conversation-quick-replies', {
    body: JSON.stringify(payload),
    method: 'POST',
  })

  return response.data
}

export async function updateConversationQuickReply(
  quickReplyId: string,
  payload: ConversationQuickReplyPayload,
): Promise<ConversationQuickReply> {
  const response = await requestJson<ApiEnvelope<ConversationQuickReply>>(
    `/api/app/conversation-quick-replies/${quickReplyId}`,
    {
      body: JSON.stringify(payload),
      method: 'PATCH',
    },
  )

  return response.data
}

export async function getConversationAiStyle(): Promise<ConversationAiStyle> {
  const response = await requestJson<ApiEnvelope<ConversationAiStyle>>('/api/app/conversation-ai-style')

  return response.data
}

export async function updateConversationAiStyle(payload: ConversationAiStyle): Promise<ConversationAiStyle> {
  const response = await requestJson<ApiEnvelope<ConversationAiStyle>>('/api/app/conversation-ai-style', {
    body: JSON.stringify(payload),
    method: 'PATCH',
  })

  return response.data
}

export async function retryConversationMessage(conversationId: string, messageId: string): Promise<Conversation> {
  const response = await requestJson<ApiEnvelope<Conversation>>(
    `/api/app/conversations/${conversationId}/messages/${messageId}/retry`,
    { method: 'POST' },
  )

  return response.data
}

export async function acknowledgeConversationAlert(conversationId: string, alertId: string): Promise<ConversationAlert> {
  const response = await requestJson<ApiEnvelope<ConversationAlert>>(
    `/api/app/conversations/${conversationId}/alerts/${alertId}/acknowledge`,
    { method: 'POST' },
  )

  return response.data
}

export async function resolveConversationAlert(conversationId: string, alertId: string): Promise<ConversationAlert> {
  const response = await requestJson<ApiEnvelope<ConversationAlert>>(
    `/api/app/conversations/${conversationId}/alerts/${alertId}/resolve`,
    { method: 'POST' },
  )

  return response.data
}

export async function approveConversationPaymentProof(
  conversationId: string,
  proofId: string,
  payload: ApproveConversationPaymentPayload,
): Promise<Conversation> {
  const response = await requestJson<ApiEnvelope<Conversation>>(
    `/api/app/conversations/${conversationId}/payment-proofs/${proofId}/approve`,
    {
      body: JSON.stringify(payload),
      method: 'POST',
    },
  )

  return response.data
}

export async function rejectConversationPaymentProof(
  conversationId: string,
  proofId: string,
  payload: RejectConversationPaymentPayload,
): Promise<Conversation> {
  const response = await requestJson<ApiEnvelope<Conversation>>(
    `/api/app/conversations/${conversationId}/payment-proofs/${proofId}/reject`,
    {
      body: JSON.stringify(payload),
      method: 'POST',
    },
  )

  return response.data
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

export async function getAdminMenuComponents(date?: string): Promise<AdminMenuComponentsResponse> {
  const response = await requestJson<ApiEnvelope<AdminMenuComponentsResponse>>(`/api/app/menu/admin/components${dateQuery(date)}`)

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

export async function updateProductComponentOption(
  optionId: number | string,
  payload: UpdateProductComponentOptionPayload,
): Promise<StructuredMenuProduct> {
  const response = await requestJson<ApiEnvelope<StructuredMenuProduct>>(
    `/api/app/menu/product-component-options/${optionId}`,
    {
      body: JSON.stringify(payload),
      method: 'PATCH',
    },
  )

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

async function getStructuredOperationalProducts(): Promise<Product[]> {
  const dailyMenu = await getDailyStructuredMenu()
  const dailyMeats = dailyMenu.sections.meat ?? []

  return dailyMenu.catalog.categories
    .flatMap((category) => category.products.map((product) => mapStructuredProduct(product, category.name, dailyMeats)))
    .filter((product) => product.available)
}

export async function createMenuProduct(payload: CreateMenuProductPayload): Promise<StructuredMenuProduct> {
  const response = await requestJson<ApiEnvelope<StructuredMenuProduct>>('/api/app/menu/products', {
    body: JSON.stringify(payload),
    method: 'POST',
  })

  return response.data
}

export async function deleteMenuProduct(productId: number | string): Promise<DeleteMenuProductResult> {
  const response = await requestJson<ApiEnvelope<DeleteMenuProductResult>>(`/api/app/menu/products/${productId}`, {
    method: 'DELETE',
  })

  return response.data
}

export async function createMenuCategory(payload: SaveMenuCategoryPayload): Promise<StructuredMenuCategorySummary> {
  const response = await requestJson<ApiEnvelope<StructuredMenuCategorySummary>>('/api/app/menu/categories', {
    body: JSON.stringify(payload),
    method: 'POST',
  })

  return response.data
}

export async function updateMenuCategory(
  categoryId: number | string,
  payload: SaveMenuCategoryPayload,
): Promise<StructuredMenuCategorySummary> {
  const response = await requestJson<ApiEnvelope<StructuredMenuCategorySummary>>(`/api/app/menu/categories/${categoryId}`, {
    body: JSON.stringify(payload),
    method: 'PATCH',
  })

  return response.data
}

export async function deleteMenuCategory(categoryId: number | string): Promise<void> {
  await requestJson<ApiEnvelope<{ outcome: 'deleted'; message: string }>>(`/api/app/menu/categories/${categoryId}`, {
    method: 'DELETE',
  })
}

export async function uploadMenuProductImage(productId: number | string, image: File): Promise<StructuredMenuProduct> {
  const form = new FormData()
  form.append('image', image)

  const response = await requestJson<ApiEnvelope<StructuredMenuProduct>>(`/api/app/menu/products/${productId}/image`, {
    body: form,
    method: 'POST',
  })

  return response.data
}

export async function removeMenuProductImage(productId: number | string): Promise<StructuredMenuProduct> {
  const response = await requestJson<ApiEnvelope<StructuredMenuProduct>>(`/api/app/menu/products/${productId}/image`, {
    method: 'DELETE',
  })

  return response.data
}

export async function getResolvedProductConfiguration(productId: number | string, date?: string): Promise<ResolvedProductConfiguration> {
  const separator = date ? '&' : '?'
  const response = await requestJson<ApiEnvelope<ResolvedProductConfiguration>>(
    `/api/app/menu/products/${productId}/configuration${dateQuery(date)}${separator}resolved=1`,
  )

  return response.data
}

function mapStructuredProduct(
  product: StructuredMenuProduct,
  categoryName: string,
  dailyMeats: DailyMenuComponent[] = [],
): Product {
  return {
    id: String(product.id),
    slug: product.slug,
    category: product.category?.name ?? categoryName,
    name: product.name,
    description: product.description ?? product.notes_hint ?? '',
    price: (product.base_price_cents ?? 0) / 100,
    available: product.availability.available,
    tags: [],
    options: [],
    structuredGroups: product.groups,
    meatConfiguration: product.meat_configuration,
    additions: product.additions,
    dailyMeatOptions: product.meat_configuration ? dailyMeats : [],
    comboItems: product.combo_items,
    usesWeeklyMenu: product.uses_weekly_menu,
    fixedComponentsRemovable: product.fixed_components_removable,
    removableGroupCodes: product.removable_group_codes,
    configurationPending: product.configuration_pending,
    serviceDays: product.service_days,
  }
}

function dateQuery(date?: string): string {
  return date ? `?date=${encodeURIComponent(date)}` : ''
}

async function requestJson<T = unknown>(path: string, init: RequestInit = {}): Promise<T> {
  const method = (init.method ?? 'GET').toUpperCase()
  const headers = new Headers(init.headers)

  headers.set('Accept', 'application/json')

  if (init.body && !(init.body instanceof FormData) && !headers.has('Content-Type')) {
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

    const code = typeof payload === 'object' && payload !== null && 'code' in payload
      ? String(payload.code)
      : undefined

    throw new ApiError(message, response.status, payload, code)
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
