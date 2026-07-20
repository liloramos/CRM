import { Icon, type IconName } from './Icon'

type StatCardProps = {
  icon: IconName
  label: string
  value: string
  trend?: string
  tone?: 'brand' | 'success' | 'warning' | 'info'
  showDecoration?: boolean
  showActionIndicator?: boolean
  detailLabel?: string
  onClick?: () => void
}

export function StatCard({
  detailLabel,
  icon,
  label,
  onClick,
  showActionIndicator = true,
  showDecoration = true,
  tone = 'brand',
  trend,
  value,
}: StatCardProps) {
  const content = (
    <>
      <div className="stat-card__header">
        <span className="stat-card__icon">
          <Icon name={icon} size={19} />
        </span>
        <span>{label}</span>
      </div>
      <strong>{value}</strong>
      {trend ? <span className="stat-card__trend">{trend}</span> : null}
      {showDecoration ? <span className="sparkline" aria-hidden="true" /> : null}
      {onClick && showActionIndicator ? (
        <span className="stat-card__action" aria-hidden="true">
          <Icon name="arrow" size={16} />
        </span>
      ) : null}
    </>
  )

  if (onClick) {
    return (
      <button
        aria-label={`${detailLabel ?? 'Ver detalhes'}: ${label}`}
        className={`stat-card stat-card--${tone} stat-card--button`}
        onClick={onClick}
        type="button"
      >
        {content}
      </button>
    )
  }

  return (
    <div className={`stat-card stat-card--${tone}`}>
      {content}
    </div>
  )
}
