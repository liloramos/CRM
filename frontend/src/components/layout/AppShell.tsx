import type { ReactNode } from 'react'
import type { RouteKey } from '../../types/crm'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

type AppShellProps = {
  activeRoute: RouteKey
  children: ReactNode
  onNavigate: (route: RouteKey) => void
  onNewOrder: () => void
}

export function AppShell({ activeRoute, children, onNavigate, onNewOrder }: AppShellProps) {
  return (
    <div className="app-shell">
      <Sidebar activeRoute={activeRoute} onNavigate={onNavigate} />
      <div className="app-shell__content">
        <Topbar activeRoute={activeRoute} onNewOrder={onNewOrder} />
        {children}
      </div>
    </div>
  )
}
