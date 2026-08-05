export function normalizeText(value: unknown): string {
  return String(value ?? '').replace(/^\uFEFF/, '').trim()
}

export function normalizeHeader(value: string): string {
  return normalizeText(value)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()
    .replace(/[\s-]+/g, '_')
}

export function normalizeUsername(value: unknown): string {
  const trimmed = normalizeText(value)

  if (!trimmed) {
    return ''
  }

  const profileMatch = trimmed.match(/(?:https?:\/\/)?(?:www\.)?instagram\.com\/([^/?#\s]+)/i)
  const candidate = profileMatch?.[1] ?? trimmed

  return candidate
    .trim()
    .replace(/^@+/, '')
    .replace(/\/+$/, '')
    .toLowerCase()
}

export function normalizeState(value: unknown): string {
  const normalized = normalizeText(value)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toUpperCase()

  if (!normalized) {
    return ''
  }

  if (normalized === 'SAO PAULO') {
    return 'SP'
  }

  if (normalized === 'RIO DE JANEIRO') {
    return 'RJ'
  }

  return normalized.length === 2 ? normalized : normalized.slice(0, 2)
}

export function parseNumber(value: unknown): number {
  const text = normalizeText(value)

  if (!text) {
    return 0
  }

  const isNegative = text.startsWith('-')
  const digits = text.replace(/[^\d]/g, '')

  if (!digits) {
    return 0
  }

  const parsed = Number(`${isNegative ? '-' : ''}${digits}`)

  return Number.isFinite(parsed) ? Math.max(0, parsed) : 0
}

export function parseBoolean(value: unknown): boolean {
  const normalized = normalizeText(value)
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '')
    .toLowerCase()

  if (['true', '1', 'sim', 'yes', 'y', 'comercial', 'business'].includes(normalized)) {
    return true
  }

  if (['false', '0', 'nao', 'no', 'n'].includes(normalized)) {
    return false
  }

  return false
}

export function ensureUrl(value: string): string {
  return /^https?:\/\//i.test(value) ? value : `https://${value}`
}
