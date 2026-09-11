import type { RouteKey, SystemAssistantAction } from '../../types/crm'

export const SYSTEM_ASSISTANT_HISTORY_TTL_MS = 2 * 60 * 60 * 1000
export const SYSTEM_ASSISTANT_HISTORY_MAX_ENTRIES = 60

const MAX_ENTRY_LENGTH = 1200
const MAX_TOTAL_LENGTH = 36_000
const STORAGE_VERSION = 1

const actionTypes: SystemAssistantAction['type'][] = [
  'explain_feature', 'navigate_to_page', 'open_order', 'open_customer', 'show_pending_payments',
  'show_deliveries', 'open_menu', 'open_company_settings', 'open_finance', 'open_reports',
]
const routeKeys: RouteKey[] = [
  'dashboard', 'conversas', 'caixa', 'pedidos', 'cardapio', 'entregas', 'pagamentos', 'financeiro',
  'clientes', 'relatorios', 'configuracoes', 'whatsapp', 'ia', 'assistente', 'suporte', 'perfil', 'empresa',
  'configuracoes-gerais', 'configuracoes-usuarios', 'configuracoes-marca', 'configuracoes-impressao',
  'configuracoes-pagamentos', 'configuracoes-seguranca',
]

export type SystemAssistantHistoryEntry = {
  id: string
  role: 'user' | 'assistant'
  text: string
  action: SystemAssistantAction | null
  state?: 'normal' | 'error'
}

type StoredHistory = {
  version: 1
  lastActivityAt: string
  entries: SystemAssistantHistoryEntry[]
}

export function systemAssistantHistoryKey(companyId: string, userId: string): string {
  return `chatbotcrm:system-assistant:v1:${encodeURIComponent(companyId)}:${encodeURIComponent(userId)}`
}

export function loadSystemAssistantHistory(companyId: string, userId: string, now = Date.now()): SystemAssistantHistoryEntry[] {
  const key = systemAssistantHistoryKey(companyId, userId)

  try {
    const decoded: unknown = JSON.parse(window.localStorage.getItem(key) ?? 'null')
    if (!isStoredHistory(decoded) || now - Date.parse(decoded.lastActivityAt) > SYSTEM_ASSISTANT_HISTORY_TTL_MS) {
      window.localStorage.removeItem(key)
      return []
    }

    return trimSystemAssistantEntries(decoded.entries)
  } catch {
    window.localStorage.removeItem(key)
    return []
  }
}

export function saveSystemAssistantHistory(companyId: string, userId: string, entries: SystemAssistantHistoryEntry[]): SystemAssistantHistoryEntry[] {
  const trimmed = trimSystemAssistantEntries(entries)
  const key = systemAssistantHistoryKey(companyId, userId)

  try {
    if (trimmed.length === 0) {
      window.localStorage.removeItem(key)
    } else {
      const payload: StoredHistory = { version: STORAGE_VERSION, lastActivityAt: new Date().toISOString(), entries: trimmed }
      window.localStorage.setItem(key, JSON.stringify(payload))
    }
  } catch {
    // The assistant remains usable when storage is unavailable or full.
  }

  return trimmed
}

export function clearSystemAssistantHistory(companyId: string, userId: string): void {
  try {
    window.localStorage.removeItem(systemAssistantHistoryKey(companyId, userId))
  } catch {
    // State is still cleared even when the browser blocks localStorage.
  }
}

export function trimSystemAssistantEntries(entries: SystemAssistantHistoryEntry[]): SystemAssistantHistoryEntry[] {
  const valid = entries.filter(isHistoryEntry).slice(-SYSTEM_ASSISTANT_HISTORY_MAX_ENTRIES)
  const result: SystemAssistantHistoryEntry[] = []
  let size = 0

  for (const entry of [...valid].reverse()) {
    if (size + entry.text.length > MAX_TOTAL_LENGTH) break
    result.unshift(entry)
    size += entry.text.length
  }

  return result
}

function isStoredHistory(value: unknown): value is StoredHistory {
  if (!value || typeof value !== 'object') return false
  const candidate = value as Partial<StoredHistory>
  return candidate.version === STORAGE_VERSION
    && typeof candidate.lastActivityAt === 'string'
    && Number.isFinite(Date.parse(candidate.lastActivityAt))
    && Array.isArray(candidate.entries)
    && candidate.entries.every(isHistoryEntry)
}

function isHistoryEntry(value: unknown): value is SystemAssistantHistoryEntry {
  if (!value || typeof value !== 'object') return false
  const entry = value as Partial<SystemAssistantHistoryEntry>
  return typeof entry.id === 'string'
    && (entry.role === 'user' || entry.role === 'assistant')
    && typeof entry.text === 'string'
    && entry.text.length > 0
    && entry.text.length <= MAX_ENTRY_LENGTH
    && (entry.state === undefined || entry.state === 'normal' || entry.state === 'error')
    && (entry.action === null || isAction(entry.action))
}

function isAction(value: unknown): value is SystemAssistantAction {
  if (!value || typeof value !== 'object') return false
  const action = value as Partial<SystemAssistantAction>
  if (!actionTypes.includes(action.type as SystemAssistantAction['type'])
    || !routeKeys.includes(action.target as RouteKey)
    || typeof action.label !== 'string'
    || !action.parameters
    || typeof action.parameters !== 'object') return false

  const { order_id: orderId, customer_id: customerId } = action.parameters
  return (orderId === undefined || typeof orderId === 'string')
    && (customerId === undefined || typeof customerId === 'string')
}
