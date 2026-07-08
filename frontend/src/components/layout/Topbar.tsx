import type { RouteKey } from '../../types/crm'
import { IconButton } from '../ui/Button'
import { Icon } from '../ui/Icon'

type TopbarProps = {
  activeRoute: RouteKey
  onNewOrder: () => void
}

export function Topbar({ activeRoute, onNewOrder }: TopbarProps) {
  const searchLabel = activeRoute === 'conversas' ? 'Buscar conversas ou clientes' : 'Buscar no CRM'

  return (
    <header className="topbar">
      <label className="search-box">
        <Icon name="search" size={18} />
        <input aria-label={searchLabel} placeholder={searchLabel} />
      </label>
      <div className="topbar__actions">
        <span className="connection-pill">
          <span className="connection-pill__dot" />
          Atendimento online
        </span>
        <IconButton icon="bell" label="Notificacoes" />
        <button className="topbar__new-order" onClick={onNewOrder} type="button">
          <Icon name="plus" size={17} />
          <span>Novo pedido</span>
        </button>
        <div className="user-chip">
          <span className="avatar">AD</span>
          <div>
            <strong>Administrador</strong>
            <small>Sol Restaurante</small>
          </div>
        </div>
      </div>
    </header>
  )
}
