import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'

export function PublicOnlyRoute() {
  const { isAuthenticated, isLoading, user } = useAuth()

  if (isLoading) {
    return <p className="app-loading">Checking your session…</p>
  }

  return isAuthenticated ? <Navigate to={user?.role === 'ADMIN' ? '/admin' : '/session'} replace /> : <Outlet />
}
