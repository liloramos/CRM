import type { ButtonHTMLAttributes, ReactNode } from 'react'
import { Icon, type IconName } from './Icon'

type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  variant?: 'primary' | 'secondary' | 'ghost' | 'danger'
  size?: 'sm' | 'md'
  icon?: IconName
  children: ReactNode
}

export function Button({
  children,
  className = '',
  icon,
  size = 'md',
  type = 'button',
  variant = 'secondary',
  ...props
}: ButtonProps) {
  return (
    <button className={`button button--${variant} button--${size} ${className}`} type={type} {...props}>
      {icon ? <Icon name={icon} size={16} /> : null}
      <span>{children}</span>
    </button>
  )
}

type IconButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & {
  icon: IconName
  label: string
  variant?: 'secondary' | 'ghost' | 'danger'
}

export function IconButton({ className = '', icon, label, type = 'button', variant = 'ghost', ...props }: IconButtonProps) {
  return (
    <button
      aria-label={label}
      className={`icon-button icon-button--${variant} ${className}`}
      title={label}
      type={type}
      {...props}
    >
      <Icon name={icon} size={18} />
    </button>
  )
}
