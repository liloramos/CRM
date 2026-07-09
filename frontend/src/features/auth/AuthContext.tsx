import { useCallback, useEffect, useMemo, useState, type ReactNode } from 'react'
import { getSession, login as loginRequest, logout as logoutRequest } from '../../services/crm.service'
import type { AuthUser } from '../../types/crm'
import { AuthContext, type AuthStatus } from './auth-state'

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<AuthUser | null>(null)
  const [status, setStatus] = useState<AuthStatus>('checking')
  const [error, setError] = useState<string | null>(null)

  const refresh = useCallback(async () => {
    setStatus('checking')
    setError(null)

    try {
      const session = await getSession()
      setUser(session.user)
      setStatus(session.authenticated ? 'authenticated' : 'unauthenticated')
    } catch (sessionError) {
      setUser(null)
      setStatus('unauthenticated')
      setError(sessionError instanceof Error ? sessionError.message : 'Nao foi possivel verificar a sessao.')
    }
  }, [])

  useEffect(() => {
    const timeout = window.setTimeout(() => {
      void refresh()
    }, 0)

    return () => window.clearTimeout(timeout)
  }, [refresh])

  const login = useCallback(async (email: string, password: string, remember = false) => {
    setError(null)

    const session = await loginRequest({ email, password, remember })

    setUser(session.user)
    setStatus(session.authenticated ? 'authenticated' : 'unauthenticated')
  }, [])

  const logout = useCallback(async () => {
    setError(null)
    await logoutRequest()
    setUser(null)
    setStatus('unauthenticated')
  }, [])

  const value = useMemo(
    () => ({
      error,
      login,
      logout,
      refresh,
      status,
      user,
    }),
    [error, login, logout, refresh, status, user],
  )

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}
