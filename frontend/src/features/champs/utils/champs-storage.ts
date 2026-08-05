import { leadFromStoredValue, mergeLeads, type Lead } from './champs-leads'

export const STORAGE_KEY = 'champs.mvp.leads.v1'

type ChampsStorage = Pick<Storage, 'getItem' | 'setItem'>

export function readStoredLeads(storage = getBrowserStorage()): Lead[] {
  if (!storage) {
    return []
  }

  try {
    const stored = storage.getItem(STORAGE_KEY)
    const parsed = stored ? JSON.parse(stored) : []

    if (!Array.isArray(parsed)) {
      return []
    }

    return mergeLeads([], parsed.map(leadFromStoredValue).filter((lead): lead is Lead => lead !== null))
  } catch {
    return []
  }
}

export function writeStoredLeads(leads: Lead[], storage = getBrowserStorage()): boolean {
  if (!storage) {
    return false
  }

  try {
    storage.setItem(STORAGE_KEY, JSON.stringify(leads))
    return true
  } catch {
    return false
  }
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
