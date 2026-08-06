import { Archive, ChevronRight, Heart, MoreHorizontal, RotateCcw } from 'lucide-react'
import type { ChampsLead } from '../services/champs.service'
import { classificationTone } from '../utils/champs-results'
import { formatDateTime, isFollowUpOverdue } from '../utils/saved-leads'
import { LeadPriorityBadge, LeadStageBadge } from './SavedLeadBadges'

type SavedLeadsTableProps = {
  isMutating: boolean
  leads: ChampsLead[]
  onArchive: (lead: ChampsLead) => void
  onOpen: (lead: ChampsLead) => void
  onRestore: (lead: ChampsLead) => void
  onToggleFavorite: (lead: ChampsLead) => void
}

export function SavedLeadsTable({
  isMutating,
  leads,
  onArchive,
  onOpen,
  onRestore,
  onToggleFavorite,
}: SavedLeadsTableProps) {
  if (leads.length === 0) {
    return (
      <div className="champs-empty saved-leads-empty">
        <strong>Nenhum lead salvo neste recorte.</strong>
        <span>Favorite um lead, mova-o no pipeline ou agende um acompanhamento para trazê-lo à central.</span>
      </div>
    )
  }

  return (
    <>
      <div className="saved-leads-table-wrap">
        <table className="saved-leads-table">
          <colgroup>
            <col className="saved-leads-col--favorite" />
            <col className="saved-leads-col--lead" />
            <col className="saved-leads-col--pipeline" />
            <col className="saved-leads-col--assignee" />
            <col className="saved-leads-col--follow-up" />
            <col className="saved-leads-col--score" />
            <col className="saved-leads-col--action" />
          </colgroup>
          <thead>
            <tr>
              <th aria-label="Favorito" />
              <th>Lead</th>
              <th>Pipeline</th>
              <th>Responsável</th>
              <th>Próxima ação</th>
              <th>Score</th>
              <th aria-label="Ações" />
            </tr>
          </thead>
          <tbody>
            {leads.map((lead) => (
              <tr className={lead.archivedAt ? 'is-archived' : undefined} key={lead.id}>
                <td>
                  <FavoriteButton disabled={isMutating} lead={lead} onClick={() => onToggleFavorite(lead)} />
                </td>
                <td><LeadIdentity lead={lead} /></td>
                <td>
                  <div className="saved-lead-badge-stack">
                    <LeadStageBadge stage={lead.pipelineStage} />
                    <LeadPriorityBadge priority={lead.priority} />
                  </div>
                </td>
                <td>{lead.assignedUser?.name ?? <span className="saved-leads-muted">Sem responsável</span>}</td>
                <td><FollowUpCell lead={lead} /></td>
                <td><ScoreCell lead={lead} /></td>
                <td>
                  <button className="saved-leads-open" onClick={() => onOpen(lead)} type="button">
                    Detalhes
                    <ChevronRight aria-hidden="true" size={16} />
                  </button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      <div className="saved-lead-cards">
        {leads.map((lead) => (
          <article className={lead.archivedAt ? 'saved-lead-card is-archived' : 'saved-lead-card'} key={lead.id}>
            <div className="saved-lead-card__header">
              <FavoriteButton disabled={isMutating} lead={lead} onClick={() => onToggleFavorite(lead)} />
              <ScoreCell lead={lead} />
            </div>
            <LeadIdentity lead={lead} />
            <div className="saved-lead-badge-stack">
              <LeadStageBadge stage={lead.pipelineStage} />
              <LeadPriorityBadge priority={lead.priority} />
            </div>
            <dl className="saved-lead-card__details">
              <div><dt>Responsável</dt><dd>{lead.assignedUser?.name ?? 'Sem responsável'}</dd></div>
              <div><dt>Próxima ação</dt><dd><FollowUpCell lead={lead} /></dd></div>
            </dl>
            <div className="saved-lead-card__actions">
              <button className="saved-leads-open" onClick={() => onOpen(lead)} type="button">
                Ver detalhes
                <ChevronRight aria-hidden="true" size={16} />
              </button>
              <details className="saved-lead-actions-menu">
                <summary aria-label={`Mais ações para ${lead.name}`}><MoreHorizontal aria-hidden="true" size={19} /></summary>
                <div>
                  <button disabled={isMutating} onClick={() => onToggleFavorite(lead)} type="button">
                    <Heart aria-hidden="true" size={15} />
                    {lead.isFavorite ? 'Desfavoritar' : 'Favoritar'}
                  </button>
                  {lead.archivedAt ? (
                    <button disabled={isMutating} onClick={() => onRestore(lead)} type="button">
                      <RotateCcw aria-hidden="true" size={15} />
                      Restaurar
                    </button>
                  ) : (
                    <button disabled={isMutating} onClick={() => onArchive(lead)} type="button">
                      <Archive aria-hidden="true" size={15} />
                      Arquivar
                    </button>
                  )}
                </div>
              </details>
            </div>
          </article>
        ))}
      </div>
    </>
  )
}

function FavoriteButton({ disabled, lead, onClick }: { disabled: boolean; lead: ChampsLead; onClick: () => void }) {
  const label = lead.isFavorite ? `Remover ${lead.name} dos favoritos` : `Adicionar ${lead.name} aos favoritos`

  return (
    <button
      aria-label={label}
      className={lead.isFavorite ? 'saved-lead-favorite is-active' : 'saved-lead-favorite'}
      disabled={disabled}
      onClick={onClick}
      title={label}
      type="button"
    >
      <Heart aria-hidden="true" fill={lead.isFavorite ? 'currentColor' : 'none'} size={18} />
    </button>
  )
}

function LeadIdentity({ lead }: { lead: ChampsLead }) {
  return (
    <div className="saved-lead-identity">
      <strong title={lead.name}>{lead.name}</strong>
      <small>{[lead.city, lead.state].filter(Boolean).join('/') || 'Localização não informada'}</small>
      {lead.instagramUsername ? <span>@{lead.instagramUsername}</span> : null}
    </div>
  )
}

function FollowUpCell({ lead }: { lead: ChampsLead }) {
  if (!lead.nextFollowUpAt) {
    return <span className="saved-leads-muted">Sem agendamento</span>
  }

  return (
    <span className={isFollowUpOverdue(lead.nextFollowUpAt) ? 'saved-lead-follow-up is-overdue' : 'saved-lead-follow-up'}>
      {formatDateTime(lead.nextFollowUpAt)}
    </span>
  )
}

function ScoreCell({ lead }: { lead: ChampsLead }) {
  if (lead.score === undefined || !lead.classification) {
    return <span className="saved-leads-muted">Sem score</span>
  }

  return (
    <div className="saved-lead-score">
      <strong className={`champs-score champs-score--${classificationTone(lead.classification)}`}>{lead.score}</strong>
      <small>{lead.classification}</small>
    </div>
  )
}
