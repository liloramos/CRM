import type { Lead } from './champs-leads'

export type ChampsStateFilter = 'TODOS' | 'SP' | 'RJ' | 'OUTROS'

export type ChampsLeadFilters = {
  stateFilter: ChampsStateFilter
  minimumScore: number
  query: string
}

export function filterLeads(leads: Lead[], filters: ChampsLeadFilters): Lead[] {
  const normalizedQuery = filters.query.trim().toLocaleLowerCase('pt-BR')

  return [...leads]
    .filter((lead) => matchesStateFilter(lead, filters.stateFilter))
    .filter((lead) => lead.score >= filters.minimumScore)
    .filter((lead) => matchesTextQuery(lead, normalizedQuery))
    .sort((a, b) => b.score - a.score || b.followersCount - a.followersCount)
}

function matchesStateFilter(lead: Lead, stateFilter: ChampsStateFilter): boolean {
  if (stateFilter === 'TODOS') {
    return true
  }

  if (stateFilter === 'OUTROS') {
    return lead.state !== 'SP' && lead.state !== 'RJ'
  }

  return lead.state === stateFilter
}

function matchesTextQuery(lead: Lead, normalizedQuery: string): boolean {
  if (!normalizedQuery) {
    return true
  }

  return [
    lead.instagramUsername,
    lead.displayName,
    lead.city,
    lead.state,
    lead.website,
    lead.email,
  ].some((value) => value.toLocaleLowerCase('pt-BR').includes(normalizedQuery))
}
