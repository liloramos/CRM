import { createElement } from 'react'
import { renderToStaticMarkup } from 'react-dom/server'
import { describe, expect, it } from 'vitest'
import { LeadPriorityBadge, LeadStageBadge } from '../components/SavedLeadBadges'
import { SavedLeadsStats } from '../components/SavedLeadsStats'
import type { ChampsLead } from '../services/champs.service'
import {
  buildSavedLeadsExportCsv,
  instagramUrl,
  isFollowUpOverdue,
  whatsappUrl,
} from './saved-leads'

const lead: ChampsLead = {
  id: 90,
  provider: 'google_places',
  externalId: 'place-export-alpha',
  name: 'Clínica; Alpha "Fictícia"',
  formattedAddress: 'Rua Teste, 10',
  city: 'São Paulo',
  state: 'SP',
  phone: '(11) 99999-0000',
  email: 'contato@alpha.example',
  website: 'alpha.example',
  rating: 4.9,
  userRatingCount: 220,
  businessStatus: 'OPERATIONAL',
  instagramUsername: 'alpha_ficticia',
  instagramProfileUrl: null,
  instagramFollowersCount: 6000,
  instagramMediaCount: 12,
  instagramIsProfessional: true,
  isFavorite: true,
  pipelineStage: 'interested',
  priority: 'high',
  assignedUserId: 12,
  assignedUser: { id: 12, name: 'Marcelo Fictício' },
  nextFollowUpAt: '2026-08-08T15:00:00+00:00',
  lastContactedAt: null,
  commercialNotes: 'Prioridade alta.',
  archivedAt: null,
  activitiesCount: 2,
  score: 85,
  classification: 'Alta prioridade',
  reasons: ['Site identificado'],
  criteria: {},
  qualified: true,
  createdAt: '2026-08-06T15:00:00+00:00',
  updatedAt: '2026-08-06T15:00:00+00:00',
}

describe('saved leads utilities and components', () => {
  it('exporta o recorte operacional em CSV compatível com Excel', () => {
    const csv = buildSavedLeadsExportCsv([lead])

    expect(csv.startsWith('\uFEFF')).toBe(true)
    expect(csv).toContain('Etapa;Prioridade;Responsável')
    expect(csv).toContain('"Clínica; Alpha ""Fictícia"""')
    expect(csv).toContain('Interessado;Alta;Marcelo Fictício;85')
  })

  it('normaliza os links de Instagram e WhatsApp sem inventar canais', () => {
    expect(instagramUrl(lead)).toBe('https://www.instagram.com/alpha_ficticia')
    expect(whatsappUrl(lead.phone)).toBe('https://wa.me/5511999990000')
    expect(whatsappUrl('123')).toBeNull()
  })

  it('identifica acompanhamentos vencidos a partir de uma referência controlada', () => {
    expect(isFollowUpOverdue('2026-08-06T10:00:00.000Z', new Date('2026-08-06T12:00:00.000Z'))).toBe(true)
    expect(isFollowUpOverdue('2026-08-06T14:00:00.000Z', new Date('2026-08-06T12:00:00.000Z'))).toBe(false)
  })

  it('renderiza badges e resumo operacional com conteúdo acessível', () => {
    const badges = renderToStaticMarkup(createElement('div', null,
      createElement(LeadStageBadge, { stage: 'meeting_scheduled' }),
      createElement(LeadPriorityBadge, { priority: 'urgent' }),
    ))
    const stats = renderToStaticMarkup(createElement(SavedLeadsStats, {
      summary: { saved: 12, interested: 4, contacted: 3, meetings: 2, overdue: 1 },
    }))

    expect(badges).toContain('Reunião agendada')
    expect(badges).toContain('Urgente')
    expect(stats).toContain('Leads salvos')
    expect(stats).toContain('Acompanhamentos vencidos')
  })
})
