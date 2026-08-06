import { ChevronDown, ExternalLink } from 'lucide-react'
import { Fragment, useState } from 'react'
import type { ChampsLead, ChampsSearchResult } from '../services/champs.service'
import { ensureUrl } from '../utils/champs-normalizers'
import { classificationTone, type ChampsResultContext } from '../utils/champs-results'

type ChampsLeadTableProps = {
  context?: ChampsResultContext
  emptyDescription: string
  results: ChampsSearchResult[]
}

const CRITERION_LABELS: Record<string, string> = {
  priority_state: 'Localização em SP ou RJ',
  website: 'Website informado',
  phone: 'Telefone ou WhatsApp informado',
  email: 'E-mail informado',
  professional_instagram: 'Perfil profissional no Instagram',
  recent_instagram_posts: 'Pelo menos 6 publicações',
  instagram_followers: 'Pelo menos 5.000 seguidores',
  name_and_city: 'Nome e cidade preenchidos',
}

export function ChampsLeadTable({ context = {}, emptyDescription, results }: ChampsLeadTableProps) {
  const [expandedResultId, setExpandedResultId] = useState<number | null>(null)

  if (results.length === 0) {
    return (
      <div className="champs-empty champs-results-empty">
        <strong>Nenhum lead para exibir.</strong>
        <span>{emptyDescription}</span>
      </div>
    )
  }

  function toggleDetails(resultId: number) {
    setExpandedResultId((currentId) => currentId === resultId ? null : resultId)
  }

  return (
    <>
      <div className="champs-table-wrap champs-leads-desktop">
        <table className="champs-table champs-table--api">
          <colgroup>
            <col className="champs-lead-column--position" />
            <col className="champs-lead-column--lead" />
            <col className="champs-lead-column--location" />
            <col className="champs-lead-column--contact" />
            <col className="champs-lead-column--rating" />
            <col className="champs-lead-column--score" />
            <col className="champs-lead-column--action" />
          </colgroup>
          <thead>
            <tr>
              <th>Posição</th>
              <th>Lead</th>
              <th>Localização</th>
              <th>Contato</th>
              <th>Avaliação</th>
              <th>Score</th>
              <th aria-label="Ações" />
            </tr>
          </thead>
          <tbody>
            {results.map((result) => {
              const isExpanded = expandedResultId === result.id
              const detailsId = `champs-lead-details-${result.id}-desktop`

              return (
                <Fragment key={result.id}>
                  <tr className={result.qualified ? 'is-qualified' : undefined}>
                    <td><LeadPosition result={result} /></td>
                    <td><LeadProfile lead={result.lead} /></td>
                    <td><LeadLocation context={context} lead={result.lead} /></td>
                    <td><LeadContact lead={result.lead} /></td>
                    <td><LeadRating lead={result.lead} /></td>
                    <td><LeadScore result={result} /></td>
                    <td>
                      <DetailsButton
                        controls={detailsId}
                        expanded={isExpanded}
                        leadName={result.lead?.name}
                        onClick={() => toggleDetails(result.id)}
                      />
                    </td>
                  </tr>
                  {isExpanded ? (
                    <tr className="champs-lead-details-row">
                      <td colSpan={7}>
                        <LeadDetails id={detailsId} result={result} />
                      </td>
                    </tr>
                  ) : null}
                </Fragment>
              )
            })}
          </tbody>
        </table>
      </div>

      <div className="champs-lead-cards">
        {results.map((result) => {
          const isExpanded = expandedResultId === result.id
          const detailsId = `champs-lead-details-${result.id}-mobile`

          return (
            <article className={result.qualified ? 'champs-lead-card is-qualified' : 'champs-lead-card'} key={result.id}>
              <div className="champs-lead-card__header">
                <LeadPosition result={result} />
                <LeadScore result={result} />
              </div>
              <LeadProfile lead={result.lead} />
              <div className="champs-lead-card__summary">
                <LeadLocation context={context} lead={result.lead} />
                <LeadContact lead={result.lead} />
                <LeadRating lead={result.lead} />
              </div>
              <DetailsButton
                controls={detailsId}
                expanded={isExpanded}
                leadName={result.lead?.name}
                onClick={() => toggleDetails(result.id)}
              />
              {isExpanded ? <LeadDetails id={detailsId} result={result} /> : null}
            </article>
          )
        })}
      </div>
    </>
  )
}

function LeadPosition({ result }: { result: ChampsSearchResult }) {
  return (
    <div className="champs-lead-position">
      <span className="champs-position">#{result.position ?? '—'}</span>
      <span className={`champs-qualified champs-qualified--${result.qualified ? 'yes' : 'no'}`}>
        {result.qualified ? 'Qualificado' : 'Abaixo do corte'}
      </span>
    </div>
  )
}

function LeadProfile({ lead }: { lead: ChampsLead | null }) {
  const instagramUrl = lead?.instagramProfileUrl
    ?? (lead?.instagramUsername ? `https://instagram.com/${lead.instagramUsername}` : null)

  return (
    <div className="champs-profile">
      <strong title={lead?.name ?? undefined}>{lead?.name ?? 'Lead indisponível'}</strong>
      {instagramUrl ? (
        <a href={instagramUrl} rel="noreferrer" target="_blank">
          {lead?.instagramUsername ? `@${lead.instagramUsername}` : 'Abrir Instagram'}
          <ExternalLink aria-hidden="true" size={12} />
        </a>
      ) : null}
      <small>{formatBusinessStatus(lead?.businessStatus)}</small>
    </div>
  )
}

function LeadLocation({ context, lead }: { context: ChampsResultContext; lead: ChampsLead | null }) {
  const city = lead?.city ?? context.fallbackCity ?? 'Não informada'
  const state = lead?.state ?? context.fallbackState ?? '—'

  return (
    <div className="champs-location">
      <span>{lead?.formattedAddress ?? `${city}/${state}`}</span>
      <small>{city}/{state}</small>
    </div>
  )
}

function LeadContact({ lead }: { lead: ChampsLead | null }) {
  return (
    <div className="champs-contact">
      {lead?.website ? (
        <a href={ensureUrl(lead.website)} rel="noreferrer" target="_blank">
          Abrir site <ExternalLink aria-hidden="true" size={12} />
        </a>
      ) : null}
      {lead?.phone ? <a href={`tel:${lead.phone}`}>{lead.phone}</a> : null}
      {lead?.email ? <a href={`mailto:${lead.email}`}>{lead.email}</a> : null}
      {!lead?.website && !lead?.phone && !lead?.email ? <span>Não identificado</span> : null}
    </div>
  )
}

function LeadRating({ lead }: { lead: ChampsLead | null }) {
  if (lead?.rating === null || lead?.rating === undefined) {
    return <span className="champs-muted">Sem avaliação</span>
  }

  return (
    <div className="champs-rating">
      <strong>{lead.rating.toFixed(1)}</strong>
      <small>{formatNumber(lead.userRatingCount)} avaliações</small>
    </div>
  )
}

function LeadScore({ result }: { result: ChampsSearchResult }) {
  return (
    <div className="champs-lead-score">
      <span className={`champs-score champs-score--${classificationTone(result.classification)}`}>
        {result.score}
      </span>
      <small>{result.classification}</small>
    </div>
  )
}

type DetailsButtonProps = {
  controls: string
  expanded: boolean
  leadName?: string
  onClick: () => void
}

function DetailsButton({ controls, expanded, leadName, onClick }: DetailsButtonProps) {
  return (
    <button
      aria-controls={controls}
      aria-expanded={expanded}
      aria-label={`${expanded ? 'Ocultar' : 'Ver'} detalhes de ${leadName ?? 'lead'}`}
      className={expanded ? 'champs-table-action is-expanded' : 'champs-table-action'}
      onClick={onClick}
      type="button"
    >
      <span>{expanded ? 'Ocultar' : 'Ver detalhes'}</span>
      <ChevronDown aria-hidden="true" size={15} />
    </button>
  )
}

function LeadDetails({ id, result }: { id: string; result: ChampsSearchResult }) {
  const lead = result.lead
  const instagramUrl = lead?.instagramProfileUrl
    ?? (lead?.instagramUsername ? `https://instagram.com/${lead.instagramUsername}` : null)
  const criteria = Object.entries(result.criteria)

  return (
    <div className="champs-lead-details" id={id}>
      <section>
        <h3>Motivos do score</h3>
        {result.reasons.length > 0 ? (
          <ul className="champs-reasons">
            {result.reasons.map((reason, index) => <li key={`${reason}-${index}`}>{reason}</li>)}
          </ul>
        ) : <span className="champs-muted">Nenhum critério atendido.</span>}
      </section>

      <section>
        <h3>Critérios</h3>
        <ul className="champs-criteria">
          {criteria.map(([key, criterion]) => (
            <li className={criterion.met ? 'is-met' : undefined} key={key}>
              <span>{CRITERION_LABELS[key] ?? formatCriterionLabel(key)}</span>
              <strong>{criterion.met ? `+${criterion.points}` : `0/${criterion.maximumPoints}`}</strong>
            </li>
          ))}
        </ul>
      </section>

      <section>
        <h3>Contato e Instagram</h3>
        <dl className="champs-detail-list">
          <div><dt>Website</dt><dd>{lead?.website ? <a href={ensureUrl(lead.website)} rel="noreferrer" target="_blank">Abrir site</a> : 'Não informado'}</dd></div>
          <div><dt>Telefone</dt><dd>{lead?.phone ?? 'Não informado'}</dd></div>
          <div><dt>Instagram</dt><dd>{instagramUrl ? <a href={instagramUrl} rel="noreferrer" target="_blank">{lead?.instagramUsername ? `@${lead.instagramUsername}` : 'Abrir perfil'}</a> : 'Não identificado'}</dd></div>
          <div><dt>Seguidores</dt><dd>{formatNumber(lead?.instagramFollowersCount ?? 0)}</dd></div>
          <div><dt>Publicações</dt><dd>{formatNumber(lead?.instagramMediaCount ?? 0)}</dd></div>
          <div><dt>Perfil comercial</dt><dd>{lead?.instagramIsProfessional ? 'Confirmado' : 'Não confirmado'}</dd></div>
        </dl>
      </section>

      <section>
        <h3>Origem e qualificação</h3>
        <dl className="champs-detail-list">
          <div><dt>Origem</dt><dd>{formatProvider(lead?.provider)}</dd></div>
          <div><dt>Identificador</dt><dd>{lead?.externalId ?? 'Não informado'}</dd></div>
          <div><dt>Classificação</dt><dd>{result.classification}</dd></div>
          <div><dt>Corte da busca</dt><dd>{result.qualified ? 'Atingido' : 'Não atingido'}</dd></div>
        </dl>
      </section>
    </div>
  )
}

function formatBusinessStatus(status: string | null | undefined): string {
  switch (status) {
    case 'OPERATIONAL':
      return 'Em operação'
    case 'CLOSED_TEMPORARILY':
      return 'Fechado temporariamente'
    case 'CLOSED_PERMANENTLY':
      return 'Fechado permanentemente'
    default:
      return 'Status não informado'
  }
}

function formatProvider(provider: string | null | undefined): string {
  switch (provider) {
    case 'google_places':
      return 'Google Places'
    case 'csv':
      return 'Importação CSV'
    case 'manual':
      return 'Inclusão manual'
    default:
      return provider || 'Não informada'
  }
}

function formatCriterionLabel(value: string): string {
  const normalized = value.replaceAll('_', ' ')

  return normalized.charAt(0).toUpperCase() + normalized.slice(1)
}

function formatNumber(value: number): string {
  return new Intl.NumberFormat('pt-BR').format(value)
}
