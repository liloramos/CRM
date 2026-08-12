import { useEffect, useState, type ReactNode } from 'react'
import type { AuthUser, RouteKey } from '../../types/crm'
import { Sidebar } from './Sidebar'

const SIDEBAR_STORAGE_KEY = 'chatbotcrm.sidebar.v1.collapsed'

type AppShellProps = {
  activeRoute: RouteKey
  children: ReactNode
  onLogout: () => void
  onNavigate: (route: RouteKey) => void
  user: AuthUser | null
}

export function AppShell({
  activeRoute,
  children,
  onLogout,
  onNavigate,
  user,
}: AppShellProps) {
  const [isSidebarCollapsed, setIsSidebarCollapsed] = useState(readInitialSidebarPreference)

  useEffect(() => {
    try {
      window.localStorage.setItem(SIDEBAR_STORAGE_KEY, isSidebarCollapsed ? 'true' : 'false')
    } catch {
      // Prefer keeping navigation usable over failing on restricted storage.
    }
  }, [isSidebarCollapsed])

  return (
    <div className={isSidebarCollapsed ? 'app-shell app-shell--sidebar-collapsed' : 'app-shell'}>
      <Sidebar
        activeRoute={activeRoute}
        collapsed={isSidebarCollapsed}
        onNavigate={onNavigate}
        onLogout={onLogout}
        onToggleCollapsed={() => setIsSidebarCollapsed((current) => !current)}
        user={user}
      />
      <div className="app-shell__content">{children}</div>
    </div>
  )
}

function readInitialSidebarPreference() {
  if (typeof window === 'undefined') {
    return false
  }

  try {
    return window.localStorage.getItem(SIDEBAR_STORAGE_KEY) === 'true'
  } catch {
    return false
  }
}
