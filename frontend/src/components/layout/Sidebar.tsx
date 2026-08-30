import { useEffect, useRef, useState, type FocusEvent, type KeyboardEvent, type MouseEvent } from 'react'
import { menuItems } from '../../constants/routes'
import type { AuthUser, RouteKey } from '../../types/crm'
import { Badge } from '../ui/Badge'
import { Icon } from '../ui/Icon'
import { SolLogo } from './SolLogo'
import { initialsFromName } from '../../utils/formatters'

type SidebarProps = {
  activeRoute: RouteKey
  collapsed: boolean
  conversationUnreadCount?: number
  onLogout: () => void
  onNavigate: (route: RouteKey) => void
  onToggleCollapsed: () => void
  user: AuthUser | null
}

export function Sidebar({ activeRoute, collapsed, conversationUnreadCount = 0, onLogout, onNavigate, onToggleCollapsed, user }: SidebarProps) {
  const toggleLabel = collapsed ? 'Expandir menu' : 'Recolher menu'
  const activeItemRef = useRef<HTMLButtonElement | null>(null)
  const [tooltip, setTooltip] = useState<{ label: string; top: number } | null>(null)
  const [isProfileOpen, setIsProfileOpen] = useState(false)
  const profileRef = useRef<HTMLDivElement>(null)

  useEffect(() => {
    activeItemRef.current?.scrollIntoView({ block: 'nearest' })
  }, [activeRoute, collapsed])

  useEffect(() => {
    if (!isProfileOpen) {
      return undefined
    }

    function handlePointerDown(event: PointerEvent) {
      if (!profileRef.current?.contains(event.target as Node)) {
        setIsProfileOpen(false)
      }
    }

    function handleEscape(event: globalThis.KeyboardEvent) {
      if (event.key === 'Escape') {
        setIsProfileOpen(false)
      }
    }

    document.addEventListener('pointerdown', handlePointerDown)
    document.addEventListener('keydown', handleEscape)
    return () => {
      document.removeEventListener('pointerdown', handlePointerDown)
      document.removeEventListener('keydown', handleEscape)
    }
  }, [isProfileOpen])

  function showTooltip(label: string, event: FocusEvent<HTMLButtonElement> | MouseEvent<HTMLButtonElement>) {
    if (!collapsed) {
      return
    }

    const rect = event.currentTarget.getBoundingClientRect()
    setTooltip({
      label,
      top: rect.top + rect.height / 2,
    })
  }

  return (
    <aside className={collapsed ? 'sidebar sidebar--collapsed' : 'sidebar'}>
      <SolLogo compact={collapsed} />
      <button
        aria-label={toggleLabel}
        className="sidebar__toggle"
        onClick={onToggleCollapsed}
        title={toggleLabel}
        type="button"
      >
        <Icon name={collapsed ? 'chevron-right' : 'chevron-left'} size={15} />
      </button>
      <nav className="sidebar__nav" aria-label="Menu principal">
        {menuItems.map((item) => {
          const badge = item.key === 'conversas' && conversationUnreadCount > 0
            ? String(conversationUnreadCount)
            : item.badge

          return (
          <button
            aria-current={activeRoute === item.key ? 'page' : undefined}
            aria-label={item.label}
            className={activeRoute === item.key ? 'sidebar__item is-active' : 'sidebar__item'}
            key={item.key}
            onBlur={() => setTooltip(null)}
            onClick={() => onNavigate(item.key)}
            onFocus={(event) => showTooltip(item.label, event)}
            onMouseEnter={(event) => showTooltip(item.label, event)}
            onMouseLeave={() => setTooltip(null)}
            ref={activeRoute === item.key ? activeItemRef : undefined}
            type="button"
          >
            <Icon name={item.icon} size={19} />
            <span className="sidebar__item-label">{item.label}</span>
            {badge ? (
              <Badge tone={item.key === 'conversas' ? 'danger' : 'brand'} size="sm">
                {formatCompactBadge(badge)}
              </Badge>
            ) : null}
          </button>
          )
        })}
      </nav>
      <div className="sidebar__footer">
        <div className="sidebar__profile" ref={profileRef}>
          <button
            aria-expanded={isProfileOpen}
            aria-haspopup="menu"
            className="sidebar__profile-trigger"
            onClick={() => setIsProfileOpen((current) => !current)}
            onKeyDown={(event: KeyboardEvent<HTMLButtonElement>) => {
              if (event.key === 'ArrowUp' || event.key === 'Enter' || event.key === ' ') {
                event.preventDefault()
                setIsProfileOpen(true)
              }
            }}
            type="button"
          >
            <span className="avatar">{initialsFromName(user?.name ?? 'Usuario')}</span>
            <span className="sidebar__profile-copy">
              <strong>{user?.name ?? 'Operador'}</strong>
              <small>{formatRole(user?.roles[0])}</small>
            </span>
            {!collapsed ? <Icon name="arrow" size={15} /> : null}
          </button>
          {isProfileOpen ? (
            <div className="sidebar__profile-menu" role="menu">
              <div className="sidebar__profile-identity">
                <span className="avatar avatar--lg">{initialsFromName(user?.name ?? 'Usuario')}</span>
                <div>
                  <strong>{user?.name ?? 'Operador'}</strong>
                  <span>{user?.email ?? 'Conta local'}</span>
                  <small>{user?.company?.name ?? 'Restaurante atual'}</small>
                </div>
              </div>
              <button
                className="sidebar__profile-action"
                onClick={() => {
                  setIsProfileOpen(false)
                  onLogout()
                }}
                role="menuitem"
                type="button"
              >
                <Icon name="logout" size={17} />
                <span>Sair</span>
              </button>
            </div>
          ) : null}
        </div>
        <button
          aria-label="Ajuda e suporte"
          className="sidebar__item sidebar__item--support"
          onBlur={() => setTooltip(null)}
          onFocus={(event) => showTooltip('Ajuda e suporte', event)}
          onMouseEnter={(event) => showTooltip('Ajuda e suporte', event)}
          onMouseLeave={() => setTooltip(null)}
          type="button"
        >
          <Icon name="chat" size={18} />
          <span className="sidebar__item-label">Ajuda e suporte</span>
        </button>
      </div>
      {collapsed && tooltip ? (
        <span className="sidebar__floating-tooltip" role="tooltip" style={{ top: tooltip.top }}>
          {tooltip.label}
        </span>
      ) : null}
    </aside>
  )
}

function formatRole(role?: string): string {
  switch (role) {
    case 'super_admin':
      return 'Super admin'
    case 'admin_gerente':
      return 'Gerência'
    case 'atendente':
      return 'Atendimento'
    case 'cozinha':
      return 'Cozinha'
    default:
      return 'Operação'
  }
}

function formatCompactBadge(value: string) {
  const numericValue = Number(value)

  if (!Number.isFinite(numericValue)) {
    return value
  }

  return numericValue > 99 ? '99+' : value
}
