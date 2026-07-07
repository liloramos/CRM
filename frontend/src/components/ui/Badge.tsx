import type { BadgeTone } from '../../types/crm'

type BadgeProps = {
  children: string
  tone?: BadgeTone
  size?: 'sm' | 'md'
}

export function Badge({ children, size = 'md', tone = 'neutral' }: BadgeProps) {
  return <span className={`badge badge--${tone} badge--${size}`}>{children}</span>
}
