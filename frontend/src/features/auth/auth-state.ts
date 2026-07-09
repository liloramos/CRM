import { createContext, useContext } from 'react'
import type { AuthUser } from '../../types/crm'

export type AuthStatus = 'checking' | 'authenticated' | 'unauthenticated'

export type AuthContextValue = {
  user: AuthUser | null
  status: AuthStatus
  error: string | null
  login: (email: string, password: string, remember?: boolean) => Promise<void>
  logout: () => Promise<void>
  refresh: () => Promise<void>
}

export const AuthContext = createContext<AuthContextValue | null>(null)

export function useAuth() {
  const context = useContext(AuthContext)

  if (!context) {
    throw new Error('useAuth must be used within AuthProvider')
  }

  return context
}
