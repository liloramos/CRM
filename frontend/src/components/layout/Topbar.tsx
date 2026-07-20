import { useEffect, useRef, useState, type KeyboardEvent } from 'react'
import type { AuthUser } from '../../types/crm'
import { IconButton } from '../ui/Button'
import { Icon } from '../ui/Icon'
import { initialsFromName } from '../../utils/formatters'

type TopbarProps = {
  isSyncing: boolean
  lastSyncedAt: Date | null
  onLogout: () => void
  onRefresh: () => void
  user: AuthUser | null
}

export function Topbar({ isSyncing, lastSyncedAt, onLogout, onRefresh, user }: TopbarProps) {
  const [isProfileOpen, setIsProfileOpen] = useState(false)
  const menuRef = useRef<HTMLDivElement>(null)
  const companyName = user?.company?.name ?? 'Restaurante atual'
  const roleLabel = formatRole(user?.roles[0])
  const syncedLabel = lastSyncedAt
    ? `Atualizado ${lastSyncedAt.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })}`
    : 'Aguardando sincronização'

  useEffect(() => {
    if (!isProfileOpen) {
      return undefined
    }

    function handlePointerDown(event: PointerEvent) {
      if (!menuRef.current?.contains(event.target as Node)) {
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

  function handleProfileKeyDown(event: KeyboardEvent<HTMLButtonElement>) {
    if (event.key === 'ArrowDown' || event.key === 'Enter' || event.key === ' ') {
      event.preventDefault()
      setIsProfileOpen(true)
    }
  }

  return (
    <header className="topbar">
      <div className="topbar__left">
        <span>{syncedLabel}</span>
      </div>

      <div className="topbar__actions">
        <IconButton
          className={isSyncing ? 'topbar__sync is-syncing' : 'topbar__sync'}
          disabled={isSyncing}
          icon="refresh"
          label="Sincronizar dados"
          onClick={onRefresh}
        />
        <IconButton disabled icon="bell" label="Notificações ainda não configuradas" />

        <div className="user-menu" ref={menuRef}>
          <button
            aria-expanded={isProfileOpen}
            aria-haspopup="menu"
            className="user-chip user-chip--button"
            onClick={() => setIsProfileOpen((current) => !current)}
            onKeyDown={handleProfileKeyDown}
            type="button"
          >
            <span className="avatar">{initialsFromName(user?.name ?? 'Usuario')}</span>
            <span className="user-chip__copy">
              <strong>{user?.name ?? 'Operador'}</strong>
              <small>{roleLabel}</small>
            </span>
            <Icon name="arrow" size={15} />
          </button>

          {isProfileOpen ? (
            <div className="user-menu__popover" role="menu">
              <div className="user-menu__identity">
                <span className="avatar avatar--lg">{initialsFromName(user?.name ?? 'Usuario')}</span>
                <div>
                  <strong>{user?.name ?? 'Operador'}</strong>
                  <span>{user?.email ?? 'Conta local'}</span>
                  <small>{companyName}</small>
                </div>
              </div>
              <button
                className="user-menu__item"
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
      </div>
    </header>
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
