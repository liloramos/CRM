import type { ReactNode } from 'react'

type PageHeaderProps = {
  title: string
  description: string
  eyebrow?: string
  actions?: ReactNode
}

export function PageHeader({ actions, description, eyebrow, title }: PageHeaderProps) {
  return (
    <div className="page-header">
      <div>
        {eyebrow ? <span className="eyebrow">{eyebrow}</span> : null}
        <h1>{title}</h1>
        <p>{description}</p>
      </div>
      {actions ? <div className="page-header__actions">{actions}</div> : null}
    </div>
  )
}
