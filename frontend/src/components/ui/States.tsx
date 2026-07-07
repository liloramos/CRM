import { Button } from './Button'
import { Icon } from './Icon'

type StateProps = {
  title: string
  description?: string
  actionLabel?: string
  onAction?: () => void
}

export function EmptyState({ actionLabel, description, onAction, title }: StateProps) {
  return (
    <div className="state state--empty">
      <Icon name="spark" size={28} />
      <h3>{title}</h3>
      {description ? <p>{description}</p> : null}
      {actionLabel ? (
        <Button icon="plus" onClick={onAction} variant="primary">
          {actionLabel}
        </Button>
      ) : null}
    </div>
  )
}

export function LoadingState({ description, title }: StateProps) {
  return (
    <div className="state state--loading">
      <span className="loader" />
      <h3>{title}</h3>
      {description ? <p>{description}</p> : null}
    </div>
  )
}

export function ErrorState({ actionLabel, description, onAction, title }: StateProps) {
  return (
    <div className="state state--error">
      <Icon name="alert" size={28} />
      <h3>{title}</h3>
      {description ? <p>{description}</p> : null}
      {actionLabel ? (
        <Button icon="arrow" onClick={onAction} variant="secondary">
          {actionLabel}
        </Button>
      ) : null}
    </div>
  )
}
