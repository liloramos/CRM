import { menuItems } from '../../constants/routes'
import type { RouteKey } from '../../types/crm'
import { Badge } from '../ui/Badge'
import { Icon } from '../ui/Icon'
import { SolLogo } from './SolLogo'

type SidebarProps = {
  activeRoute: RouteKey
  onNavigate: (route: RouteKey) => void
}

export function Sidebar({ activeRoute, onNavigate }: SidebarProps) {
  return (
    <aside className="sidebar">
      <SolLogo />
      <nav className="sidebar__nav" aria-label="Menu principal">
        {menuItems.map((item) => (
          <button
            className={activeRoute === item.key ? 'sidebar__item is-active' : 'sidebar__item'}
            key={item.key}
            onClick={() => onNavigate(item.key)}
            type="button"
          >
            <Icon name={item.icon} size={19} />
            <span>{item.label}</span>
            {item.badge ? <Badge tone="brand" size="sm">{item.badge}</Badge> : null}
          </button>
        ))}
      </nav>
      <div className="sidebar__footer">
        <div className="plan-card">
          <span>Plano operacional</span>
          <strong>Ambiente local</strong>
          <div className="plan-card__bar">
            <span />
          </div>
        </div>
        <button className="sidebar__item sidebar__item--support" type="button">
          <Icon name="chat" size={18} />
          <span>Ajuda e suporte</span>
        </button>
      </div>
    </aside>
  )
}
