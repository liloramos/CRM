import type { ReactNode } from 'react'
import type { AuthUser, RouteKey, SnapshotSource } from '../../types/crm'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

type AppShellProps = {
  activeRoute: RouteKey
  apiSource: SnapshotSource
  children: ReactNode
  fallbackReason: string | null
  isSyncing: boolean
  onLogout: () => void
  onNavigate: (route: RouteKey) => void
  onNewOrder: () => void
  onRefresh: () => void
  user: AuthUser | null
}

export function AppShell({
  activeRoute,
  apiSource,
  children,
  fallbackReason,
  isSyncing,
  onLogout,
  onNavigate,
  onNewOrder,
  onRefresh,
  user,
}: AppShellProps) {
  return (
    <div className="app-shell">
      <Sidebar activeRoute={activeRoute} apiSource={apiSource} fallbackReason={fallbackReason} onNavigate={onNavigate} />
      <div className="app-shell__content">
        <Topbar
          activeRoute={activeRoute}
          apiSource={apiSource}
          isSyncing={isSyncing}
          onLogout={onLogout}
          onNewOrder={onNewOrder}
          onRefresh={onRefresh}
          user={user}
        />
        {children}
      </div>
    </div>
  )
}
