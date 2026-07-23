import { useEffect, useRef, useState, type FocusEvent, type MouseEvent } from 'react'
import { menuItems } from '../../constants/routes'
import type { RouteKey } from '../../types/crm'
import { Badge } from '../ui/Badge'
import { Icon } from '../ui/Icon'
import { SolLogo } from './SolLogo'

type SidebarProps = {
  activeRoute: RouteKey
  collapsed: boolean
  onNavigate: (route: RouteKey) => void
  onToggleCollapsed: () => void
}

export function Sidebar({ activeRoute, collapsed, onNavigate, onToggleCollapsed }: SidebarProps) {
  const toggleLabel = collapsed ? 'Expandir menu' : 'Recolher menu'
  const activeItemRef = useRef<HTMLButtonElement | null>(null)
  const [tooltip, setTooltip] = useState<{ label: string; top: number } | null>(null)

  useEffect(() => {
    activeItemRef.current?.scrollIntoView({ block: 'nearest' })
  }, [activeRoute, collapsed])

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
        {menuItems.map((item) => (
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
            {item.badge ? (
              <Badge tone="brand" size="sm">
                {formatCompactBadge(item.badge)}
              </Badge>
            ) : null}
          </button>
        ))}
      </nav>
      <div className="sidebar__footer">
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

function formatCompactBadge(value: string) {
  const numericValue = Number(value)

  if (!Number.isFinite(numericValue)) {
    return value
  }

  return numericValue > 99 ? '99+' : value
}
