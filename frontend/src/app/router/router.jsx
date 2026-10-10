import { createBrowserRouter } from 'react-router-dom'
import { PublicOnlyRoute } from '../../features/auth/guards/PublicOnlyRoute'
import { RequireAuth } from '../../features/auth/guards/RequireAuth'
import { RequireRole } from '../../features/auth/guards/RequireRole'
import { AdminLayout } from '../layouts/AdminLayout'
import { StudentLayout } from '../layouts/StudentLayout'
import { CoordinatorLayout } from '../layouts/CoordinatorLayout'
import { SupervisorLayout } from '../layouts/SupervisorLayout'
import { SupervisorInternshipsPage } from '../../features/tasks/pages/SupervisorInternshipsPage'
import { SupervisorInternshipDetailsPage } from '../../features/tasks/pages/SupervisorInternshipDetailsPage'
import { TaskDetailsPage } from '../../features/tasks/pages/TaskDetailsPage'
import { TaskFormPage } from '../../features/tasks/pages/TaskFormPage'
import { SupervisorEvaluationPage } from '../../features/evaluations/pages/SupervisorEvaluationPage'
import { ActivitiesPage } from '../../features/activities/pages/ActivitiesPage'
import { InternshipActivitiesPage } from '../../features/activities/pages/InternshipActivitiesPage'
import { ActivityDetailsPage } from '../../features/activities/pages/ActivityDetailsPage'
import { ActivityFormPage } from '../../features/activities/pages/ActivityFormPage'
import { SupervisorActivitiesPage } from '../../features/activities/pages/SupervisorActivitiesPage'
import { MonitoringInternshipsPage } from '../../features/monitoring/pages/MonitoringInternshipsPage'
import { MonitoringInternshipPage } from '../../features/monitoring/pages/MonitoringInternshipPage'
import { MonitoringActivitiesPage, MonitoringActivityPage } from '../../features/monitoring/pages/MonitoringActivitiesPage'
import { MonitoringTaskPage } from '../../features/monitoring/pages/MonitoringTaskPage'
import { CoordinatorInternshipsPage } from '../../features/internships/pages/CoordinatorInternshipsPage'
import { CoordinatorInternshipDetailsPage } from '../../features/internships/pages/CoordinatorInternshipDetailsPage'
import { StudentInternshipsPage } from '../../features/internships/pages/StudentInternshipsPage'
import { InternshipDraftPage } from '../../features/internships/pages/InternshipDraftPage'
import { InternshipDetailsPage } from '../../features/internships/pages/InternshipDetailsPage'
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
            element: <RequireRole allowedRoles={['COMPANY_SUPERVISOR']} />,
            children: [{ element: <SupervisorLayout />, children: [
              { path: 'supervisor/internships', element: <SupervisorInternshipsPage /> },
              { path: 'supervisor/internships/:id', element: <SupervisorInternshipDetailsPage /> },
              { path: 'supervisor/internships/:id/final-evaluation', element: <SupervisorEvaluationPage /> },
              { path: 'supervisor/internships/:internshipId/activities', element: <SupervisorActivitiesPage /> },
              { path: 'supervisor/activities/:id', element: <ActivityDetailsPage supervisor /> },
              { path: 'supervisor/internships/:internshipId/tasks/new', element: <TaskFormPage /> },
              { path: 'supervisor/tasks/:id', element: <TaskDetailsPage /> },
              { path: 'supervisor/tasks/:id/edit', element: <TaskFormPage /> },
            ] }],
          },
          {
            element: <RequireRole allowedRoles={['STUDENT']} />,
            children: [{ element: <StudentLayout />, children: [
              { path: 'student/tasks/:id', element: <TaskDetailsPage student /> },
              { path: 'student/activities', element: <ActivitiesPage /> },
              { path: 'student/internships/:internshipId/activities', element: <InternshipActivitiesPage /> },
              { path: 'student/activities/:id', element: <ActivityDetailsPage /> },
              { path: 'student/activities/:id/edit', element: <ActivityFormPage /> },
              { path: 'student/internships/:internshipId/activities/new', element: <ActivityFormPage /> },
            ] }, { path: 'student/internships', element: <StudentLayout />, children: [
              { index: true, element: <StudentInternshipsPage /> },
              { path: 'new', element: <InternshipDraftPage key="new" /> },
              { path: ':id', element: <InternshipDetailsPage /> },
              { path: ':id/edit', element: <InternshipDraftPage key="edit" /> },
            ] }],
          },
          {
            element: <RequireRole allowedRoles={['ACADEMIC_COORDINATOR']} />,
            children: [{ element: <CoordinatorLayout />, children: [
              { path: 'coordinator/monitoring/internships', element: <MonitoringInternshipsPage /> },
              { path: 'coordinator/monitoring/internships/:id', element: <MonitoringInternshipPage /> },
              { path: 'coordinator/monitoring/internships/:id/activities', element: <MonitoringActivitiesPage /> },
              { path: 'coordinator/monitoring/activities/:id', element: <MonitoringActivityPage /> },
              { path: 'coordinator/monitoring/tasks/:id', element: <MonitoringTaskPage /> },
            ] }, { path: 'coordinator/internships', element: <CoordinatorLayout />, children: [
              { index: true, element: <CoordinatorInternshipsPage /> },
              { path: ':id', element: <CoordinatorInternshipDetailsPage /> },
            ] }],
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
