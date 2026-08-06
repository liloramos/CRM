import { beforeEach, describe, expect, it, vi } from 'vitest'
import { requestJson } from '../../../services/crm.service'
import {
  archiveSavedLead,
  createLeadActivity,
  listLeadActivities,
  listSavedLeads,
  restoreSavedLead,
  serializeLeadActivity,
  serializeSavedLeadsFilters,
  updateLeadAssignment,
  updateLeadFavorite,
  updateLeadFollowUp,
  updateLeadPipeline,
} from './saved-leads.service'

vi.mock('../../../services/crm.service', async (importOriginal) => {
  const original = await importOriginal<typeof import('../../../services/crm.service')>()

  return {
    ...original,
    requestJson: vi.fn(),
  }
})

const leadResource = {
  id: 51,
  provider: 'google_places',
  external_id: 'place-saved-alpha',
  name: 'Clínica Alpha Fictícia',
  formatted_address: 'Avenida Fictícia, 100',
  city: 'São Paulo',
  state: 'SP',
  phone: '11999990000',
  email: 'contato@alpha.example',
  website: 'https://alpha.example',
  rating: 4.8,
  user_rating_count: 102,
  business_status: 'OPERATIONAL',
  instagram_username: 'alpha_ficticia',
  instagram_profile_url: 'https://instagram.com/alpha_ficticia',
  instagram_followers_count: 6500,
  instagram_media_count: 15,
  instagram_is_professional: true,
  is_favorite: true,
  pipeline_stage: 'interested',
  priority: 'high',
  assigned_user_id: 9,
  assigned_user: { id: 9, name: 'Marcelo Fictício' },
  next_follow_up_at: '2026-08-08T15:00:00+00:00',
  last_contacted_at: '2026-08-06T15:00:00+00:00',
  commercial_notes: 'Retornar na sexta-feira.',
  archived_at: null,
  activities_count: 2,
  score: 85,
  classification: 'Alta prioridade',
  reasons: ['Site identificado'],
  criteria: { website: { met: true, points: 15, maximum_points: 15 } },
  qualified: true,
  created_at: '2026-08-05T12:00:00+00:00',
  updated_at: '2026-08-06T15:00:00+00:00',
}

const collectionResponse = {
  data: [leadResource],
  links: { first: null, last: null, prev: null, next: null },
  meta: {
    current_page: 1,
    from: 1,
    last_page: 1,
    path: '/api/champs/saved-leads',
    per_page: 50,
    to: 1,
    total: 1,
  },
  summary: { saved: 1, interested: 1, contacted: 0, meetings: 0, overdue: 0 },
  assignees: [{ id: 9, name: 'Marcelo Fictício' }],
}

describe('saved leads service', () => {
  beforeEach(() => {
    vi.mocked(requestJson).mockReset()
  })

  it('serializa os filtros operacionais sem perder valores booleanos falsos', () => {
    expect(serializeSavedLeadsFilters({
      query: '  clínica  ',
      pipelineStage: 'interested',
      priority: 'high',
      assignedUserId: 'unassigned',
      state: 'sp',
      minimumScore: 70,
      followUp: 'overdue',
      hasInstagram: false,
      hasWebsite: true,
      archived: 'only',
      orderBy: 'score',
      direction: 'desc',
      page: 2,
      perPage: 50,
    })).toEqual({
      query: 'clínica',
      pipeline_stage: 'interested',
      priority: 'high',
      assigned_user_id: 'unassigned',
      state: 'SP',
      minimum_score: 70,
      follow_up: 'overdue',
      has_instagram: false,
      has_website: true,
      archived: 'only',
      order_by: 'score',
      direction: 'desc',
      page: 2,
      per_page: 50,
    })
  })

  it('adapta a lista, os indicadores e os responsáveis do contrato da API', async () => {
    vi.mocked(requestJson).mockResolvedValue(collectionResponse)

    const response = await listSavedLeads({
      hasInstagram: false,
      archived: 'active',
      orderBy: 'score',
      perPage: 50,
    })

    expect(requestJson).toHaveBeenCalledWith(
      '/api/champs/saved-leads?has_instagram=false&archived=active&order_by=score&per_page=50',
    )
    expect(response.data[0]).toMatchObject({
      isFavorite: true,
      pipelineStage: 'interested',
      priority: 'high',
      assignedUser: { id: 9 },
      score: 85,
    })
    expect(response.summary).toEqual(collectionResponse.summary)
    expect(response.assignees).toEqual(collectionResponse.assignees)
  })

  it('envia mutações para os endpoints operacionais corretos', async () => {
    vi.mocked(requestJson)
      .mockResolvedValueOnce({ data: leadResource })
      .mockResolvedValueOnce({ data: leadResource })
      .mockResolvedValueOnce({ data: leadResource })
      .mockResolvedValueOnce({ data: leadResource })
      .mockResolvedValueOnce({ data: leadResource })
      .mockResolvedValueOnce({ data: leadResource })

    await updateLeadFavorite(51, true)
    await updateLeadPipeline(51, { pipelineStage: 'contacted', priority: 'urgent' })
    await updateLeadAssignment(51, null)
    await updateLeadFollowUp(51, '2026-08-08T15:00:00.000Z')
    await archiveSavedLead(51)
    await restoreSavedLead(51)

    expect(vi.mocked(requestJson).mock.calls).toEqual([
      ['/api/champs/leads/51/favorite', { body: JSON.stringify({ is_favorite: true }), method: 'PATCH' }],
      ['/api/champs/leads/51/pipeline', { body: JSON.stringify({ pipeline_stage: 'contacted', priority: 'urgent' }), method: 'PATCH' }],
      ['/api/champs/leads/51/assignment', { body: JSON.stringify({ assigned_user_id: null }), method: 'PATCH' }],
      ['/api/champs/leads/51/follow-up', { body: JSON.stringify({ next_follow_up_at: '2026-08-08T15:00:00.000Z' }), method: 'PATCH' }],
      ['/api/champs/leads/51/archive', { method: 'PATCH' }],
      ['/api/champs/leads/51/restore', { method: 'PATCH' }],
    ])
  })

  it('cria e lista atividades sem expor contratos paralelos', async () => {
    vi.mocked(requestJson)
      .mockResolvedValueOnce({
        data: {
          id: 81,
          type: 'note',
          description: 'Contexto comercial.',
          metadata: null,
          user: { id: 9, name: 'Marcelo Fictício' },
          created_at: '2026-08-06T15:00:00+00:00',
          updated_at: '2026-08-06T15:00:00+00:00',
        },
      })
      .mockResolvedValueOnce({
        data: [],
        links: { first: null, last: null, prev: null, next: null },
        meta: {
          current_page: 1,
          from: null,
          last_page: 1,
          path: '/api/champs/leads/51/activities',
          per_page: 50,
          to: null,
          total: 0,
        },
      })

    const activity = await createLeadActivity(51, { type: 'note', description: '  Contexto comercial.  ' })
    const activities = await listLeadActivities(51)

    expect(serializeLeadActivity({ type: 'contacted', occurredAt: '2026-08-06T15:00:00Z' })).toEqual({
      type: 'contacted',
      occurred_at: '2026-08-06T15:00:00Z',
    })
    expect(activity).toMatchObject({ id: 81, type: 'note', user: { id: 9 } })
    expect(activities.data).toEqual([])
    expect(vi.mocked(requestJson).mock.calls).toEqual([
      ['/api/champs/leads/51/activities', { body: JSON.stringify({ type: 'note', description: 'Contexto comercial.' }), method: 'POST' }],
      ['/api/champs/leads/51/activities?page=1&per_page=50'],
    ])
  })
})
