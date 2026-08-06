import { csvRowsToContent } from './champs-csv'
import { ensureUrl } from './champs-normalizers'
import type { ChampsLead, ChampsLeadPriority, ChampsLeadStage } from '../services/champs.service'
import type { ChampsLeadActivityType } from '../services/saved-leads.service'

export const LEAD_STAGE_LABELS: Record<ChampsLeadStage, string> = {
  new: 'Novo',
  reviewing: 'Em revisão',
  interested: 'Interessado',
  contacted: 'Contatado',
  awaiting_response: 'Aguardando resposta',
  meeting_scheduled: 'Reunião agendada',
  client: 'Cliente',
  lost: 'Perdido',
  discarded: 'Descartado',
}

export const LEAD_PRIORITY_LABELS: Record<ChampsLeadPriority, string> = {
  low: 'Baixa',
  normal: 'Normal',
  high: 'Alta',
  urgent: 'Urgente',
}

export const LEAD_ACTIVITY_LABELS: Record<ChampsLeadActivityType, string> = {
  note: 'Nota adicionada',
  stage_changed: 'Pipeline atualizado',
  favorite_added: 'Adicionado aos favoritos',
  favorite_removed: 'Removido dos favoritos',
  contacted: 'Contato registrado',
  follow_up_scheduled: 'Acompanhamento agendado',
  assigned: 'Responsável atualizado',
  exported: 'Exportado em CSV',
}

export function buildSavedLeadsExportCsv(leads: ChampsLead[]): string {
  const rows = [
    [
      'Nome',
      'Etapa',
      'Prioridade',
      'Responsável',
      'Score',
      'Classificação',
      'Estado',
      'Cidade',
      'Telefone',
      'E-mail',
      'Website',
      'Instagram',
      'Próximo acompanhamento',
      'Último contato',
      'Favorito',
      'Origem',
    ],
    ...leads.map((lead) => [
      lead.name,
      LEAD_STAGE_LABELS[lead.pipelineStage],
      LEAD_PRIORITY_LABELS[lead.priority],
      lead.assignedUser?.name ?? '',
      String(lead.score ?? ''),
      lead.classification ?? '',
      lead.state ?? '',
      lead.city ?? '',
      lead.phone ?? '',
      lead.email ?? '',
      lead.website ?? '',
      lead.instagramUsername ? `@${lead.instagramUsername}` : '',
      formatDateTime(lead.nextFollowUpAt),
      formatDateTime(lead.lastContactedAt),
      lead.isFavorite ? 'Sim' : 'Não',
      formatSource(lead),
    ]),
  ]

  return csvRowsToContent(rows)
}

export function formatDateTime(value: string | null): string {
  if (!value) {
    return 'Não definido'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return 'Não definido'
  }

  return new Intl.DateTimeFormat('pt-BR', {
    dateStyle: 'short',
    timeStyle: 'short',
  }).format(date)
}

export function toDateTimeLocal(value: string | null): string {
  if (!value) {
    return ''
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return ''
  }

  const offset = date.getTimezoneOffset() * 60_000

  return new Date(date.getTime() - offset).toISOString().slice(0, 16)
}

export function whatsappUrl(phone: string | null): string | null {
  if (!phone) {
    return null
  }

  const digits = phone.replace(/\D/g, '')

  if (digits.length < 10) {
    return null
  }

  const international = digits.startsWith('55') ? digits : `55${digits}`

  return `https://wa.me/${international}`
}

export function instagramUrl(lead: ChampsLead): string | null {
  return lead.instagramProfileUrl
    ?? (lead.instagramUsername ? `https://www.instagram.com/${lead.instagramUsername}` : null)
}

export function leadWebsiteUrl(lead: ChampsLead): string | null {
  return lead.website ? ensureUrl(lead.website) : null
}

export function isFollowUpOverdue(value: string | null, now = new Date()): boolean {
  if (!value) {
    return false
  }

  const date = new Date(value)

  return !Number.isNaN(date.getTime()) && date.getTime() < now.getTime()
}

export function formatSource(lead: ChampsLead): string {
  const sources = [lead.provider === 'google_places' ? 'Google Places' : lead.provider]

  if (lead.website) {
    sources.push('Website público')
  }

  if (lead.instagramUsername) {
    sources.push(lead.instagramIsProfessional ? 'Meta Business Discovery' : 'Instagram encontrado')
  }

  return sources.filter(Boolean).join(' + ')
}
