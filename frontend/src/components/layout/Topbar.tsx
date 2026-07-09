import type { AuthUser, RouteKey, SnapshotSource } from '../../types/crm'
import { IconButton } from '../ui/Button'
import { Icon } from '../ui/Icon'
import { initialsFromName } from '../../utils/formatters'

type TopbarProps = {
  activeRoute: RouteKey
  apiSource: SnapshotSource
  isSyncing: boolean
  onLogout: () => void
  onNewOrder: () => void
  onRefresh: () => void
  user: AuthUser | null
}

export function Topbar({ activeRoute, apiSource, isSyncing, onLogout, onNewOrder, onRefresh, user }: TopbarProps) {
  const searchLabel = activeRoute === 'conversas' ? 'Buscar conversas ou clientes' : 'Buscar no CRM'
  const companyName = user?.company?.name ?? 'Restaurante atual'

  return (
    <header className="topbar">
      <label className="search-box">
        <Icon name="search" size={18} />
        <input aria-label={searchLabel} placeholder={searchLabel} />
      </label>
      <div className="topbar__actions">
        <span className={apiSource === 'api' ? 'connection-pill' : 'connection-pill connection-pill--warning'}>
          <span className="connection-pill__dot" />
          {apiSource === 'api' ? 'API conectada' : 'Modo demo local'}
        </span>
        <IconButton disabled={isSyncing} icon="clock" label="Sincronizar dados" onClick={onRefresh} />
        <IconButton icon="bell" label="Notificacoes" />
        <button className="topbar__new-order" onClick={onNewOrder} type="button">
          <Icon name="plus" size={17} />
          <span>Novo pedido</span>
        </button>
        <div className="user-chip">
          <span className="avatar">{initialsFromName(user?.name ?? 'Usuario')}</span>
          <div>
            <strong>{user?.name ?? 'Operador'}</strong>
            <small>{companyName}</small>
          </div>
        </div>
        <IconButton icon="arrow" label="Sair" onClick={onLogout} />
      </div>
    </header>
  )
}
