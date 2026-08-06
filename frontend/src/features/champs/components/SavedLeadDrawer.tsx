import {
  AtSign,
  Archive,
  CalendarClock,
  ExternalLink,
  FileText,
  Heart,
  MessageCircle,
  RotateCcw,
  Send,
  UserRound,
  X,
} from 'lucide-react'
import { useEffect, useState, type FormEvent } from 'react'
import type { ChampsLead, ChampsLeadPriority, ChampsLeadStage, ChampsLeadAssignee } from '../services/champs.service'
import type { ChampsLeadActivity } from '../services/saved-leads.service'
import {
  LEAD_ACTIVITY_LABELS,
  LEAD_PRIORITY_LABELS,
  LEAD_STAGE_LABELS,
  formatDateTime,
  formatSource,
  instagramUrl,
  leadWebsiteUrl,
  toDateTimeLocal,
  whatsappUrl,
} from '../utils/saved-leads'
import { FilterSelect, type FilterSelectOption } from './FilterSelect'
import { LeadPriorityBadge, LeadStageBadge } from './SavedLeadBadges'

type SavedLeadDrawerProps = {
  activities: ChampsLeadActivity[]
  assignees: ChampsLeadAssignee[]
  isBusy: boolean
  isLoadingActivities: boolean
  lead: ChampsLead
  onArchive: () => void
  onAssign: (userId: number | null) => void
  onClose: () => void
  onContact: () => void
  onFollowUp: (value: string | null) => void
  onNote: (description: string) => void
  onPipeline: (payload: { pipelineStage?: ChampsLeadStage; priority?: ChampsLeadPriority }) => void
  onRestore: () => void
  onToggleFavorite: () => void
}

const stageOptions: FilterSelectOption[] = Object.entries(LEAD_STAGE_LABELS).map(([value, label]) => ({ label, value }))
const priorityOptions: FilterSelectOption[] = Object.entries(LEAD_PRIORITY_LABELS).map(([value, label]) => ({ label, value }))

export function SavedLeadDrawer({
  activities,
  assignees,
  isBusy,
  isLoadingActivities,
  lead,
  onArchive,
  onAssign,
  onClose,
  onContact,
  onFollowUp,
  onNote,
  onPipeline,
  onRestore,
  onToggleFavorite,
}: SavedLeadDrawerProps) {
  const [stage, setStage] = useState<ChampsLeadStage>(lead.pipelineStage)
  const [priority, setPriority] = useState<ChampsLeadPriority>(lead.priority)
  const [assigneeId, setAssigneeId] = useState<string>(lead.assignedUserId ? String(lead.assignedUserId) : '')
  const [followUp, setFollowUp] = useState(toDateTimeLocal(lead.nextFollowUpAt))
  const [note, setNote] = useState('')
  const website = leadWebsiteUrl(lead)
  const instagram = instagramUrl(lead)
  const whatsapp = whatsappUrl(lead.phone)
  const isArchived = lead.archivedAt !== null

  useEffect(() => {
    function handleKeyDown(event: KeyboardEvent) {
      if (event.key === 'Escape') {
        onClose()
      }
    }

    document.addEventListener('keydown', handleKeyDown)

    return () => document.removeEventListener('keydown', handleKeyDown)
  }, [onClose])

  function submitNote(event: FormEvent<HTMLFormElement>) {
    event.preventDefault()

    if (note.trim()) {
      onNote(note)
    }
  }

  return (
    <div className="saved-lead-drawer-layer" onMouseDown={onClose} role="presentation">
      <aside
        aria-label={`Detalhes de ${lead.name}`}
        aria-modal="true"
        className="saved-lead-drawer"
        onMouseDown={(event) => event.stopPropagation()}
        role="dialog"
      >
        <header className="saved-lead-drawer__header">
          <div>
            <span className="champs-section-kicker">LEAD SALVO</span>
            <h2>{lead.name}</h2>
            <p>{[lead.city, lead.state].filter(Boolean).join('/') || lead.formattedAddress || 'Localização não informada'}</p>
          </div>
          <button aria-label="Fechar detalhes" className="saved-lead-drawer__close" onClick={onClose} type="button">
            <X aria-hidden="true" size={20} />
          </button>
        </header>

        <div className="saved-lead-drawer__content">
          <section className="saved-lead-drawer__summary">
            <div className="saved-lead-badge-stack">
              <LeadStageBadge stage={lead.pipelineStage} />
              <LeadPriorityBadge priority={lead.priority} />
            </div>
            <button
              aria-label={lead.isFavorite ? 'Remover dos favoritos' : 'Adicionar aos favoritos'}
              className={lead.isFavorite ? 'saved-lead-favorite is-active' : 'saved-lead-favorite'}
              disabled={isBusy}
              onClick={onToggleFavorite}
              type="button"
            >
              <Heart aria-hidden="true" fill={lead.isFavorite ? 'currentColor' : 'none'} size={19} />
              {lead.isFavorite ? 'Favorito' : 'Favoritar'}
            </button>
          </section>

          <section className="saved-lead-drawer__links" aria-label="Canais de contato">
            {website ? <ExternalAction href={website} Icon={ExternalLink} label="Abrir site" /> : null}
            {instagram ? <ExternalAction href={instagram} Icon={AtSign} label="Instagram" /> : null}
            {whatsapp ? <ExternalAction href={whatsapp} Icon={MessageCircle} label="WhatsApp" /> : null}
            <button className="champs-button champs-button--secondary" disabled={isBusy} onClick={onContact} type="button">
              <Send aria-hidden="true" size={16} />
              Registrar contato
            </button>
          </section>

          <section className="saved-lead-editor">
            <h3>Pipeline comercial</h3>
            <div className="saved-lead-editor__grid">
              <FilterSelect
                disabled={isBusy || isArchived}
                label="Etapa"
                onChange={(value) => setStage(value as ChampsLeadStage)}
                options={stageOptions}
                value={stage}
              />
              <FilterSelect
                disabled={isBusy || isArchived}
                label="Prioridade"
                onChange={(value) => setPriority(value as ChampsLeadPriority)}
                options={priorityOptions}
                value={priority}
              />
            </div>
            <button
              className="champs-button champs-button--secondary"
              disabled={isBusy || isArchived || (stage === lead.pipelineStage && priority === lead.priority)}
              onClick={() => onPipeline({ pipelineStage: stage, priority })}
              type="button"
            >
              Salvar pipeline
            </button>
          </section>

          <section className="saved-lead-editor">
            <h3>Responsável e próxima ação</h3>
            <div className="saved-lead-editor__grid">
              <FilterSelect
                disabled={isBusy || isArchived}
                label="Responsável"
                onChange={(value) => setAssigneeId(value)}
                options={[
                  { label: 'Sem responsável', value: '' },
                  ...assignees.map((assignee) => ({ label: assignee.name, value: String(assignee.id) })),
                ]}
                value={assigneeId}
              />
              <label className="champs-filter-field">
                <span className="champs-filter-field__label">Próximo acompanhamento</span>
                <input
                  disabled={isBusy || isArchived}
                  onChange={(event) => setFollowUp(event.target.value)}
                  type="datetime-local"
                  value={followUp}
                />
              </label>
            </div>
            <div className="saved-lead-editor__actions">
              <button
                className="champs-button champs-button--secondary"
                disabled={isBusy || isArchived || assigneeId === String(lead.assignedUserId ?? '')}
                onClick={() => onAssign(assigneeId ? Number(assigneeId) : null)}
                type="button"
              >
                <UserRound aria-hidden="true" size={16} />
                Salvar responsável
              </button>
              <button
                className="champs-button champs-button--secondary"
                disabled={isBusy || isArchived || followUp === toDateTimeLocal(lead.nextFollowUpAt)}
                onClick={() => onFollowUp(followUp ? new Date(followUp).toISOString() : null)}
                type="button"
              >
                <CalendarClock aria-hidden="true" size={16} />
                Salvar acompanhamento
              </button>
            </div>
          </section>

          <section className="saved-lead-details-grid">
            <DetailItem label="Telefone" value={lead.phone ?? 'Não informado'} />
            <DetailItem label="E-mail" value={lead.email ?? 'Não informado'} />
            <DetailItem label="Avaliação" value={lead.rating === null ? 'Sem avaliação' : `${lead.rating.toFixed(1)} (${lead.userRatingCount})`} />
            <DetailItem label="Último contato" value={formatDateTime(lead.lastContactedAt)} />
            <DetailItem label="Origem" value={formatSource(lead)} />
            <DetailItem label="Perfil profissional" value={lead.instagramIsProfessional ? 'Confirmado' : 'Não confirmado'} />
          </section>

          <section className="saved-lead-score-details">
            <h3>Score e critérios</h3>
            <p>{lead.classification ?? 'Sem classificação'} {lead.score === undefined ? '' : `· ${lead.score} pontos`}</p>
            {lead.reasons && lead.reasons.length > 0 ? (
              <ul>{lead.reasons.map((reason) => <li key={reason}>{reason}</li>)}</ul>
            ) : <span className="saved-leads-muted">Nenhum critério pontuado.</span>}
            {lead.criteria ? (
              <div className="saved-lead-criteria">
                {Object.entries(lead.criteria).map(([key, criterion]) => (
                  <span className={criterion.met ? 'is-met' : undefined} key={key}>
                    {criterion.met ? `+${criterion.points}` : `0/${criterion.maximumPoints}`} {formatCriterion(key)}
                  </span>
                ))}
              </div>
            ) : null}
          </section>

          <section className="saved-lead-notes">
            <div className="saved-lead-section-heading">
              <div>
                <FileText aria-hidden="true" size={17} />
                <h3>Notas e atividades</h3>
              </div>
              {lead.activitiesCount !== undefined ? <span>{lead.activitiesCount} registro(s)</span> : null}
            </div>
            <form onSubmit={submitNote}>
              <label>
                <span className="sr-only">Adicionar nota</span>
                <textarea
                  disabled={isBusy || isArchived}
                  onChange={(event) => setNote(event.target.value)}
                  placeholder={lead.commercialNotes ?? 'Registre contexto comercial, objeções ou próximos passos'}
                  rows={3}
                  value={note}
                />
              </label>
              <button className="champs-button champs-button--secondary" disabled={isBusy || isArchived || !note.trim()} type="submit">
                Adicionar nota
              </button>
            </form>
            {isLoadingActivities ? <p className="saved-leads-muted">Carregando histórico...</p> : null}
            {!isLoadingActivities && activities.length === 0 ? <p className="saved-leads-muted">Nenhuma atividade registrada.</p> : null}
            <ol className="saved-lead-timeline">
              {activities.map((activity) => (
                <li key={activity.id}>
                  <span className="saved-lead-timeline__marker" />
                  <div>
                    <strong>{LEAD_ACTIVITY_LABELS[activity.type]}</strong>
                    <p>{activity.description ?? 'Atualização operacional registrada.'}</p>
                    <small>{activity.user?.name ?? 'Sistema'} · {formatDateTime(activity.createdAt)}</small>
                  </div>
                </li>
              ))}
            </ol>
          </section>
        </div>

        <footer className="saved-lead-drawer__footer">
          {isArchived ? (
            <button className="champs-button champs-button--secondary" disabled={isBusy} onClick={onRestore} type="button">
              <RotateCcw aria-hidden="true" size={16} />
              Restaurar lead
            </button>
          ) : (
            <button className="champs-button champs-button--danger" disabled={isBusy} onClick={onArchive} type="button">
              <Archive aria-hidden="true" size={16} />
              Arquivar lead
            </button>
          )}
        </footer>
      </aside>
    </div>
  )
}

function ExternalAction({ href, Icon, label }: { href: string; Icon: typeof ExternalLink; label: string }) {
  return (
    <a className="champs-button champs-button--secondary" href={href} rel="noreferrer" target="_blank">
      <Icon aria-hidden="true" size={16} />
      {label}
    </a>
  )
}

function DetailItem({ label, value }: { label: string; value: string }) {
  return <div><dt>{label}</dt><dd>{value}</dd></div>
}

function formatCriterion(key: string): string {
  return key.replaceAll('_', ' ').replace(/^./, (character) => character.toUpperCase())
}
