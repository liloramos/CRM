import { Icon, type IconName } from './Icon'

type StatCardProps = {
  icon: IconName
  label: string
  value: string
  trend?: string
  tone?: 'brand' | 'success' | 'warning' | 'info'
}

export function StatCard({ icon, label, tone = 'brand', trend, value }: StatCardProps) {
  return (
    <div className={`stat-card stat-card--${tone}`}>
      <div className="stat-card__header">
        <span className="stat-card__icon">
          <Icon name={icon} size={19} />
        </span>
        <span>{label}</span>
      </div>
      <strong>{value}</strong>
      {trend ? <span className="stat-card__trend">{trend}</span> : null}
      <span className="sparkline" aria-hidden="true" />
    </div>
  )
}
