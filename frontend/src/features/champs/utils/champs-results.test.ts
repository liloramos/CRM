import { describe, expect, it } from 'vitest'
import type { ChampsSearchResult } from '../services/champs.service'
import {
  buildSearchResultsExportCsv,
  filterSearchResults,
  searchStatusPresentation,
} from './champs-results'
import { validateSearchPayload } from './champs-search'

const results = [
  result({
    id: 1,
    name: 'Clínica Paulista',
    state: 'SP',
    city: 'São Paulo',
    score: 80,
    qualified: true,
    rating: 4.3,
  }),
  result({
    id: 2,
    name: 'Studio Carioca',
    state: 'RJ',
    city: 'Rio de Janeiro',
    score: 35,
    qualified: false,
    rating: 4.9,
  }),
  result({
    id: 3,
    name: 'Centro Mineiro; Premium',
    state: 'MG',
    city: 'Belo Horizonte',
    score: 50,
    qualified: false,
    rating: null,
  }),
]

const defaultFilters = {
  stateFilter: 'TODOS' as const,
  qualificationFilter: 'TODOS' as const,
  minimumScore: 0,
  query: '',
  order: 'SCORE_DESC' as const,
}

describe('resultados persistidos Champs', () => {
  it('filtra somente qualified=true', () => {
    const filtered = filterSearchResults(results, {
      ...defaultFilters,
      qualificationFilter: 'QUALIFICADOS',
    })

    expect(filtered.map((item) => item.id)).toEqual([1])
  })

  it('filtra somente qualified=false sem esconder por padrão', () => {
    const unqualified = filterSearchResults(results, {
      ...defaultFilters,
      qualificationFilter: 'NAO_QUALIFICADOS',
    })

    expect(unqualified).toHaveLength(2)
    expect(filterSearchResults(results, defaultFilters)).toHaveLength(3)
  })

  it('filtra SP, RJ e outros estados', () => {
    expect(filterSearchResults(results, { ...defaultFilters, stateFilter: 'SP' })[0].id).toBe(1)
    expect(filterSearchResults(results, { ...defaultFilters, stateFilter: 'RJ' })[0].id).toBe(2)
    expect(filterSearchResults(results, { ...defaultFilters, stateFilter: 'OUTROS' })[0].id).toBe(3)
  })

  it('aplica score mínimo e pesquisa textual sem diferenciar acentos', () => {
    expect(filterSearchResults(results, { ...defaultFilters, minimumScore: 70 })).toHaveLength(1)
    expect(filterSearchResults(results, { ...defaultFilters, query: 'clinica paulista' })[0].id).toBe(1)
  })

  it('ordena por score e avaliação', () => {
    expect(filterSearchResults(results, defaultFilters).map((item) => item.id)).toEqual([1, 3, 2])
    expect(filterSearchResults(results, { ...defaultFilters, order: 'RATING_DESC' }).map((item) => item.id))
      .toEqual([2, 1, 3])
  })

  it('exporta somente o filtro atual com BOM e escape para Excel', () => {
    const filtered = filterSearchResults(results, { ...defaultFilters, stateFilter: 'OUTROS' })
    const csv = buildSearchResultsExportCsv(filtered)

    expect(csv.startsWith('\uFEFF')).toBe(true)
    expect(csv).toContain('position;qualified;name')
    expect(csv).toContain('"Centro Mineiro; Premium"')
    expect(csv).not.toContain('Clínica Paulista')
  })

  it('apresenta todos os status da API em português', () => {
    expect(searchStatusPresentation('pending').label).toBe('Pendente')
    expect(searchStatusPresentation('processing').label).toBe('Processando')
    expect(searchStatusPresentation('completed').label).toBe('Concluída')
    expect(searchStatusPresentation('partially_completed').label).toBe('Concluída parcialmente')
    expect(searchStatusPresentation('failed').label).toBe('Falhou')
  })

  it('valida os limites do formulário antes da chamada', () => {
    expect(validateSearchPayload({
      niche: '',
      city: 'S',
      state: 'S',
      limit: 21,
      minimumScore: 101,
      excludeSeen: true,
    })).toEqual({
      niche: 'Informe um nicho entre 2 e 120 caracteres.',
      city: 'Informe uma cidade entre 2 e 120 caracteres.',
      state: 'Selecione uma UF válida.',
      limit: 'Informe uma quantidade entre 1 e 20.',
      minimumScore: 'Informe um score entre 0 e 100.',
    })
  })
})

type ResultOverrides = {
  id: number
  name: string
  state: string
  city: string
  score: number
  qualified: boolean
  rating: number | null
}

function result(overrides: ResultOverrides): ChampsSearchResult {
  return {
    id: overrides.id,
    searchId: 10,
    leadId: overrides.id,
    score: overrides.score,
    classification: overrides.score >= 70 ? 'Bom potencial' : overrides.score >= 40 ? 'Potencial médio' : 'Baixo potencial',
    reasons: ['Critério fictício'],
    criteria: {},
    qualified: overrides.qualified,
    position: overrides.id,
    lead: {
      id: overrides.id,
      provider: 'google_places',
      externalId: `place-${overrides.id}`,
      name: overrides.name,
      formattedAddress: `${overrides.city}, ${overrides.state}`,
      city: overrides.city,
      state: overrides.state,
      phone: null,
      email: null,
      website: null,
      rating: overrides.rating,
      userRatingCount: overrides.rating === null ? 0 : 100,
      businessStatus: 'OPERATIONAL',
      instagramUsername: null,
      instagramProfileUrl: null,
      instagramFollowersCount: 0,
      instagramMediaCount: 0,
      instagramIsProfessional: false,
      isFavorite: false,
      pipelineStage: 'new',
      priority: 'normal',
      assignedUserId: null,
      assignedUser: null,
      nextFollowUpAt: null,
      lastContactedAt: null,
      commercialNotes: null,
      archivedAt: null,
      createdAt: '2026-08-05T12:00:00+00:00',
      updatedAt: '2026-08-05T12:00:00+00:00',
    },
    createdAt: '2026-08-05T12:00:00+00:00',
    updatedAt: '2026-08-05T12:00:00+00:00',
  }
}
