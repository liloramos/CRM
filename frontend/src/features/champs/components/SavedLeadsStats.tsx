import { CalendarClock, Heart, MessageCircle, Presentation, Sparkles } from 'lucide-react'
import type { SavedLeadsSummary } from '../services/saved-leads.service'

type SavedLeadsStatsProps = {
  summary: SavedLeadsSummary
}

const statDefinitions = [
  { key: 'saved', label: 'Leads salvos', Icon: Heart },
  { key: 'interested', label: 'Interessados', Icon: Sparkles },
  { key: 'contacted', label: 'Contatados', Icon: MessageCircle },
  { key: 'meetings', label: 'Reuniões agendadas', Icon: Presentation },
  { key: 'overdue', label: 'Acompanhamentos vencidos', Icon: CalendarClock },
] as const

export function SavedLeadsStats({ summary }: SavedLeadsStatsProps) {
  return (
    <section aria-label="Resumo operacional" className="champs-stats saved-leads-stats">
      {statDefinitions.map(({ key, label, Icon }) => (
        <article className={key === 'overdue' && summary[key] > 0 ? 'champs-stat is-overdue' : 'champs-stat'} key={key}>
          <span>{label}</span>
          <strong>{summary[key]}</strong>
          <Icon aria-hidden="true" className="saved-leads-stat__icon" size={19} />
        </article>
      ))}
    </section>
  )
}
