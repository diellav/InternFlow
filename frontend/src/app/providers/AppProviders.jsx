import { RouterProvider } from 'react-router-dom'
import { AuthProvider } from '../../features/auth/providers/AuthProvider'
import { router } from '../router/router'

export function AppProviders() {
  return (
    <AuthProvider>
      <RouterProvider router={router} />
    </AuthProvider>
  )
}
