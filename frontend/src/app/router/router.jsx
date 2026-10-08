import { createBrowserRouter } from 'react-router-dom'
import { PublicOnlyRoute } from '../../features/auth/guards/PublicOnlyRoute'
import { RequireAuth } from '../../features/auth/guards/RequireAuth'
import { RequireRole } from '../../features/auth/guards/RequireRole'
import { AdminLayout } from '../layouts/AdminLayout'
import { CompaniesPage } from '../../features/companies/pages/CompaniesPage'
import { CompanyDetailsPage } from '../../features/companies/pages/CompanyDetailsPage'
import { SupervisorsPage } from '../../features/companies/pages/SupervisorsPage'
import { SupervisorDetailsPage } from '../../features/companies/pages/SupervisorDetailsPage'
import { VerificationApplicationPage } from '../../features/companies/pages/VerificationApplicationPage'
import { AdminHomePage } from '../../features/users/pages/AdminHomePage'
import { UsersPage } from '../../features/users/pages/UsersPage'
import { UserDetailsPage } from '../../features/users/pages/UserDetailsPage'
import { CoordinatorFormPage } from '../../features/users/pages/CoordinatorFormPage'
import { ProfileLayout, ProfilePage } from '../../features/users/pages/ProfilePage'
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
          {
            element: <ProfileLayout />,
            children: [{ path: 'profile', element: <ProfilePage /> }],
          },
          {
            element: <RequireRole allowedRoles={['COMPANY_SUPERVISOR']} />,
            children: [{ element: <ProfileLayout />, children: [{ path: 'supervisor/verification', element: <VerificationApplicationPage /> }] }],
          },
          {
            element: <RequireRole allowedRoles={['ADMIN']} />,
            children: [{
              path: 'admin',
              element: <AdminLayout />,
              children: [
                { index: true, element: <AdminHomePage /> },
                { path: 'users', element: <UsersPage /> },
                { path: 'users/:id', element: <UserDetailsPage /> },
                { path: 'companies', element: <CompaniesPage /> },
                { path: 'companies/:id', element: <CompanyDetailsPage /> },
                { path: 'supervisors', element: <SupervisorsPage /> },
                { path: 'supervisors/:id', element: <SupervisorDetailsPage /> },
                { path: 'academic-coordinators/new', element: <CoordinatorFormPage /> },
                { path: 'academic-coordinators/:id/edit', element: <CoordinatorFormPage /> },
              ],
            }],
          },
        ],
      },
    ],
  },
])
