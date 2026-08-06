import { ExternalLink } from 'lucide-react'
import type { ChampsSearchResult } from '../services/champs.service'
import { ensureUrl } from '../utils/champs-normalizers'
import { classificationTone, type ChampsResultContext } from '../utils/champs-results'

type ChampsLeadTableProps = {
  context?: ChampsResultContext
  emptyDescription: string
  results: ChampsSearchResult[]
}

export function ChampsLeadTable({ context = {}, emptyDescription, results }: ChampsLeadTableProps) {
  return (
    <div className="champs-table-wrap">
      <table className="champs-table champs-table--api">
        <thead>
          <tr>
            <th>Posição</th>
            <th>Lead</th>
            <th>Localização</th>
            <th>Contato</th>
            <th>Avaliação</th>
            <th>Score</th>
            <th>Motivos</th>
          </tr>
        </thead>
        <tbody>
          {results.length === 0 ? (
            <tr>
              <td className="champs-empty" colSpan={7}>
                <strong>Nenhum lead para exibir.</strong>
                <span>{emptyDescription}</span>
              </td>
            </tr>
          ) : (
            results.map((result) => {
              const lead = result.lead
              const city = lead?.city ?? context.fallbackCity ?? 'Não informada'
              const state = lead?.state ?? context.fallbackState ?? '—'
              const instagramUrl = lead?.instagramProfileUrl
                ?? (lead?.instagramUsername ? `https://instagram.com/${lead.instagramUsername}` : null)

              return (
                <tr className={result.qualified ? 'is-qualified' : undefined} key={result.id}>
                  <td>
                    <span className="champs-position">#{result.position ?? '—'}</span>
                    <span className={`champs-qualified champs-qualified--${result.qualified ? 'yes' : 'no'}`}>
                      {result.qualified ? 'Qualificado' : 'Abaixo do corte'}
                    </span>
                  </td>
                  <td>
                    <div className="champs-profile">
                      <strong>{lead?.name ?? 'Lead indisponível'}</strong>
                      {instagramUrl ? (
                        <a href={instagramUrl} rel="noreferrer" target="_blank">
                          {lead?.instagramUsername ? `@${lead.instagramUsername}` : 'Abrir Instagram'}
                          <ExternalLink aria-hidden="true" size={12} />
                        </a>
                      ) : null}
                      <small>{formatBusinessStatus(lead?.businessStatus)}</small>
                    </div>
                  </td>
                  <td>
                    <div className="champs-location">
                      <span>{lead?.formattedAddress ?? `${city}/${state}`}</span>
                      <small>{city}/{state}</small>
                    </div>
                  </td>
                  <td>
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
                  </td>
                  <td>
                    {lead?.rating === null || lead?.rating === undefined ? (
                      <span className="champs-muted">Sem avaliação</span>
                    ) : (
                      <div className="champs-rating">
                        <strong>{lead.rating.toFixed(1)}</strong>
                        <small>{formatNumber(lead.userRatingCount)} avaliações</small>
                      </div>
                    )}
                  </td>
                  <td>
                    <span className={`champs-score champs-score--${classificationTone(result.classification)}`}>
                      {result.score}
                    </span>
                    <small>{result.classification}</small>
                  </td>
                  <td>
                    {result.reasons.length > 0 ? (
                      <ul className="champs-reasons">
                        {result.reasons.map((reason, index) => <li key={`${reason}-${index}`}>{reason}</li>)}
                      </ul>
                    ) : (
                      <span className="champs-muted">Nenhum critério atendido</span>
                    )}
                  </td>
                </tr>
              )
            })
          )}
        </tbody>
      </table>
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

function formatNumber(value: number): string {
  return new Intl.NumberFormat('pt-BR').format(value)
}
