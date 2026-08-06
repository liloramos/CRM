import { beforeEach, describe, expect, it, vi } from 'vitest'
import { ApiError, requestJson } from '../../../services/crm.service'
import {
  adaptChampsSearch,
  champsErrorMessage,
  createSearch,
  listLeads,
  serializeCreateSearchPayload,
} from './champs.service'

vi.mock('../../../services/crm.service', async (importOriginal) => {
  const original = await importOriginal<typeof import('../../../services/crm.service')>()

  return {
    ...original,
    requestJson: vi.fn(),
  }
})

const leadResource = {
  id: 31,
  provider: 'google_places',
  external_id: 'place-alpha',
  name: 'Clínica Alpha',
  formatted_address: 'Avenida Exemplo, 100 - São Paulo, SP',
  city: 'São Paulo',
  state: 'SP',
  phone: '1130000000',
  email: null,
  website: 'https://alpha.example',
  rating: 4.8,
  user_rating_count: 240,
  business_status: 'OPERATIONAL',
  instagram_username: null,
  instagram_profile_url: null,
  instagram_followers_count: 0,
  instagram_media_count: 0,
  instagram_is_professional: false,
  created_at: '2026-08-05T12:00:00+00:00',
  updated_at: '2026-08-05T12:00:00+00:00',
}

const resultResource = {
  id: 41,
  search_id: 11,
  lead_id: 31,
  score: 50,
  classification: 'Potencial médio',
  reasons: ['Localização prioritária', 'Site identificado'],
  criteria: {
    priority_state: { met: true, points: 25, maximum_points: 25 },
    website: { met: true, points: 15, maximum_points: 15 },
  },
  qualified: true,
  position: 1,
  lead: leadResource,
  created_at: '2026-08-05T12:00:00+00:00',
  updated_at: '2026-08-05T12:00:00+00:00',
}

const searchResource = {
  id: 11,
  name: 'Clínicas Alpha em SP',
  niche: 'clínica de estética',
  city: 'São Paulo',
  state: 'SP',
  requested_limit: 20,
  provider: 'google_places',
  minimum_score: 40,
  status: 'completed',
  total_discovered: 1,
  total_saved: 1,
  total_qualified: 1,
  error_message: null,
  started_at: '2026-08-05T12:00:00+00:00',
  completed_at: '2026-08-05T12:00:02+00:00',
  created_at: '2026-08-05T12:00:00+00:00',
  updated_at: '2026-08-05T12:00:02+00:00',
  results: [resultResource],
}

describe('champs service', () => {
  beforeEach(() => {
    vi.mocked(requestJson).mockReset()
  })

  it('serializa o payload no contrato snake_case da API', () => {
    expect(serializeCreateSearchPayload({
      name: '  Busca Alpha  ',
      niche: '  clínica de estética ',
      city: ' São Paulo ',
      state: 'sp',
      limit: 5,
      minimumScore: 40,
    })).toEqual({
      name: 'Busca Alpha',
      niche: 'clínica de estética',
      city: 'São Paulo',
      state: 'SP',
      limit: 5,
      minimum_score: 40,
    })
  })

  it('cria a busca usando o cliente autenticado compartilhado', async () => {
    vi.mocked(requestJson).mockResolvedValue({ data: searchResource })

    const search = await createSearch({
      niche: 'clínica de estética',
      city: 'São Paulo',
      state: 'SP',
      limit: 20,
      minimumScore: 40,
    })

    expect(requestJson).toHaveBeenCalledWith('/api/champs/searches', {
      body: JSON.stringify({
        niche: 'clínica de estética',
        city: 'São Paulo',
        state: 'SP',
        limit: 20,
        minimum_score: 40,
      }),
      method: 'POST',
    })
    expect(search.results[0]).toMatchObject({
      score: 50,
      qualified: true,
      lead: { externalId: 'place-alpha', rating: 4.8 },
    })
  })

  it('adapta campos dos Resources para o modelo do frontend', () => {
    const search = adaptChampsSearch(searchResource)

    expect(search).toMatchObject({
      requestedLimit: 20,
      minimumScore: 40,
      totalDiscovered: 1,
      totalSaved: 1,
      totalQualified: 1,
      resultsLoaded: true,
    })
    expect(search.results[0].criteria.priority_state).toEqual({
      met: true,
      points: 25,
      maximumPoints: 25,
    })
  })

  it('preserva qualified=false ao serializar filtros de leads', async () => {
    vi.mocked(requestJson).mockResolvedValue({
      data: [leadResource],
      links: { first: null, last: null, prev: null, next: null },
      meta: {
        current_page: 1,
        from: 1,
        last_page: 1,
        path: '/api/champs/leads',
        per_page: 20,
        to: 1,
        total: 1,
      },
    })

    await listLeads({
      searchId: 11,
      minimumScore: 40,
      qualified: false,
      orderBy: 'rating',
      direction: 'desc',
      perPage: 20,
    })

    expect(requestJson).toHaveBeenCalledWith(
      '/api/champs/leads?search_id=11&minimum_score=40&qualified=false&order_by=rating&direction=desc&per_page=20',
    )
  })

  it('não repassa detalhes técnicos ou segredos em mensagens de erro', () => {
    const message = champsErrorMessage(new ApiError('token-secreto Google', 500), 'create')

    expect(message).toBe('A API do Champs está temporariamente indisponível. Tente novamente em instantes.')
    expect(message).not.toContain('token-secreto')
  })
})
