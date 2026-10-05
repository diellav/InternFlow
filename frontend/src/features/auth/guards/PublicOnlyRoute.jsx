import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'

export function PublicOnlyRoute() {
  const { isAuthenticated, isLoading } = useAuth()

  if (isLoading) {
    return <p className="app-loading">Checking your session…</p>
  }

  return isAuthenticated ? <Navigate to="/session" replace /> : <Outlet />
}
