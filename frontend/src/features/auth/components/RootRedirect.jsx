import { Navigate } from 'react-router-dom'
import { useAuth } from '../hooks/useAuth'
import { AuthLoading } from './AuthLoading'

export function RootRedirect() {
  const { isAuthenticated, isLoading } = useAuth()

  if (isLoading) {
    return <AuthLoading />
  }

  return <Navigate to={isAuthenticated ? '/session' : '/login'} replace />
}
