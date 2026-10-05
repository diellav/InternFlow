import { createBrowserRouter } from 'react-router-dom'
import { PublicOnlyRoute } from '../../features/auth/guards/PublicOnlyRoute'
import { RequireAuth } from '../../features/auth/guards/RequireAuth'
import { RootRedirect } from '../../features/auth/components/RootRedirect'
import { LoginPage } from '../../features/auth/pages/LoginPage'
import { StudentRegistrationPage } from '../../features/auth/pages/StudentRegistrationPage'
import { SupervisorRegistrationPage } from '../../features/auth/pages/SupervisorRegistrationPage'
import { SessionPage } from '../../features/auth/pages/SessionPage'
import { RootLayout } from '../layouts/RootLayout'

export const router = createBrowserRouter([
  {
    element: <RootLayout />,
    children: [
      {
        index: true,
        element: <RootRedirect />,
      },
      {
        element: <PublicOnlyRoute />,
        children: [
          {
            path: 'login',
            element: <LoginPage />,
          },
          {
            path: 'register/student',
            element: <StudentRegistrationPage />,
          },
          {
            path: 'register/supervisor',
            element: <SupervisorRegistrationPage />,
          },
        ],
      },
      {
        element: <RequireAuth />,
        children: [
          {
            path: 'session',
            element: <SessionPage />,
          },
        ],
      },
    ],
  },
])
