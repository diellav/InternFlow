import { Navigate, Outlet, useLocation } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import { NotificationProvider } from '../../notifications/providers/NotificationProvider'

export function RequireAuth() {
  const { isAuthenticated, isLoading, user } = useAuth()
  const location = useLocation()

  if (isLoading) {
    return <p className="app-loading">Checking your session…</p>
  }

  return isAuthenticated ? (
    user ? <NotificationProvider key={user.id}><Outlet /></NotificationProvider> : <Outlet />
  ) : (
    <Navigate to="/login" replace state={{ from: location }} />
  )
}
