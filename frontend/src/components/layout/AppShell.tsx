import { useEffect, useState, type ReactNode } from 'react'
import type { AuthUser, RouteKey } from '../../types/crm'
import { Sidebar } from './Sidebar'
import { Topbar } from './Topbar'

const SIDEBAR_STORAGE_KEY = 'chatbotcrm.sidebar.v1.collapsed'

type AppShellProps = {
  activeRoute: RouteKey
  children: ReactNode
  isSyncing: boolean
  lastSyncedAt: Date | null
  onLogout: () => void
  onNavigate: (route: RouteKey) => void
  onRefresh: () => void
  user: AuthUser | null
}

export function AppShell({
  activeRoute,
  children,
  isSyncing,
  lastSyncedAt,
  onLogout,
  onNavigate,
  onRefresh,
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
        onToggleCollapsed={() => setIsSidebarCollapsed((current) => !current)}
      />
      <div className="app-shell__content">
        <Topbar
          isSyncing={isSyncing}
          lastSyncedAt={lastSyncedAt}
          onLogout={onLogout}
          onRefresh={onRefresh}
          user={user}
        />
        {children}
      </div>
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
