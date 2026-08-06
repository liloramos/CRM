import type { ChampsResultsOrder, ChampsQualificationFilter } from './champs-results'
import type { ChampsStateFilter } from './champs-filters'

export const STORAGE_KEY = 'champs.ui.preferences.v1'

export type ChampsUiPreferences = {
  stateFilter: ChampsStateFilter
  qualificationFilter: ChampsQualificationFilter
  minimumScore: number
  query: string
  order: ChampsResultsOrder
  lastSearchId: number | null
}

const DEFAULT_PREFERENCES: ChampsUiPreferences = {
  stateFilter: 'TODOS',
  qualificationFilter: 'TODOS',
  minimumScore: 0,
  query: '',
  order: 'SCORE_DESC',
  lastSearchId: null,
}

type ChampsStorage = Pick<Storage, 'getItem' | 'setItem'>

export function readChampsPreferences(storage = getBrowserStorage()): ChampsUiPreferences {
  if (!storage) {
    return { ...DEFAULT_PREFERENCES }
  }

  try {
    const stored = storage.getItem(STORAGE_KEY)
    const parsed = stored ? JSON.parse(stored) : null

    if (!isRecord(parsed)) {
      return { ...DEFAULT_PREFERENCES }
    }

    return {
      stateFilter: isStateFilter(parsed.stateFilter) ? parsed.stateFilter : DEFAULT_PREFERENCES.stateFilter,
      qualificationFilter: isQualificationFilter(parsed.qualificationFilter)
        ? parsed.qualificationFilter
        : DEFAULT_PREFERENCES.qualificationFilter,
      minimumScore: normalizeScore(parsed.minimumScore),
      query: typeof parsed.query === 'string' ? parsed.query.slice(0, 120) : DEFAULT_PREFERENCES.query,
      order: isResultsOrder(parsed.order) ? parsed.order : DEFAULT_PREFERENCES.order,
      lastSearchId: normalizeSearchId(parsed.lastSearchId),
    }
  } catch {
    return { ...DEFAULT_PREFERENCES }
  }
}

export function writeChampsPreferences(
  preferences: ChampsUiPreferences,
  storage = getBrowserStorage(),
): boolean {
  if (!storage) {
    return false
  }

  try {
    storage.setItem(STORAGE_KEY, JSON.stringify(preferences))
    return true
  } catch {
    return false
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null && !Array.isArray(value)
}

function isStateFilter(value: unknown): value is ChampsStateFilter {
  return value === 'TODOS' || value === 'SP' || value === 'RJ' || value === 'OUTROS'
}

function isQualificationFilter(value: unknown): value is ChampsQualificationFilter {
  return value === 'TODOS' || value === 'QUALIFICADOS' || value === 'NAO_QUALIFICADOS'
}

function isResultsOrder(value: unknown): value is ChampsResultsOrder {
  return value === 'SCORE_DESC'
    || value === 'SCORE_ASC'
    || value === 'RATING_DESC'
    || value === 'RATING_ASC'
}

function normalizeScore(value: unknown): number {
  return typeof value === 'number' && Number.isInteger(value) && value >= 0 && value <= 100
    ? value
    : DEFAULT_PREFERENCES.minimumScore
}

function normalizeSearchId(value: unknown): number | null {
  return typeof value === 'number' && Number.isInteger(value) && value > 0 ? value : null
}

function getBrowserStorage(): ChampsStorage | null {
  if (typeof window === 'undefined') {
    return null
  }

  try {
    return window.localStorage
  } catch {
    return null
  }
}
