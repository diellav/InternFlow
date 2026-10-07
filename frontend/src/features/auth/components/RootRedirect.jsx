import { Navigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import { AuthLoading } from './AuthLoading'

export function RootRedirect() {
  const { isAuthenticated, isLoading, user } = useAuth()

  if (isLoading) {
    return <AuthLoading />
  }

  return <Navigate to={isAuthenticated ? user?.role === 'ADMIN' ? '/admin' : '/session' : '/login'} replace />
}
