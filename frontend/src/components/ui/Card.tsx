import type { ReactNode } from 'react'

type CardProps = {
  children: ReactNode
  className?: string
  tone?: 'default' | 'glow' | 'danger' | 'success'
}

export function Card({ children, className = '', tone = 'default' }: CardProps) {
  return <section className={`card card--${tone} ${className}`}>{children}</section>
}

type SectionTitleProps = {
  title: string
  eyebrow?: string
  action?: ReactNode
}

export function SectionTitle({ action, eyebrow, title }: SectionTitleProps) {
  return (
    <div className="section-title">
      <div>
        {eyebrow ? <span className="eyebrow">{eyebrow}</span> : null}
        <h2>{title}</h2>
      </div>
      {action ? <div className="section-title__action">{action}</div> : null}
    </div>
  )
}
