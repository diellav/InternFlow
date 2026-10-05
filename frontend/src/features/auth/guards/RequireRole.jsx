import { Navigate, Outlet } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'

export function RequireRole({ allowedRoles }) {
  const { user } = useAuth()

  return allowedRoles.includes(user?.role) ? <Outlet /> : <Navigate to="/" replace />
}
