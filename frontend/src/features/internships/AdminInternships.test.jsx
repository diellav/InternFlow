import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import * as api from './api/adminInternshipsApi'

vi.mock('./api/adminInternshipsApi', async original => ({ ...await original(), listAdminInternships: vi.fn(), getAdminInternship: vi.fn(), getAdminInternshipStatistics: vi.fn() }))
const admin = { id: 99, first_name: 'Admin', last_name: 'Test', role: 'ADMIN' }
const record = { id: 12, student: { id: 1, first_name: 'Arta', last_name: 'Student' }, company: { id: 2, name: 'Example Company' },
  coordinator: null, supervisor: { id: 3, first_name: 'Supervisor', last_name: 'Test' }, position_title: 'Developer', status: 'ACTIVE',
  description: 'Public description', created_at: '2026-10-01T10:00:00Z', submitted_at: '2026-10-02T10:00:00Z',
  start_date: '2026-10-03', end_date: '2026-11-01', approved_at: null, completed_at: null }
const routers = []
function page(path = '/admin/internships', user = admin) {
  const memory = createMemoryRouter(router.routes, { initialEntries: [path] })
  routers.push(memory)
  render(<AuthContext.Provider value={{ user, isAuthenticated: !!user, isLoading: false, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>)
  return memory
}
beforeEach(() => {
  vi.resetAllMocks()
  api.listAdminInternships.mockImplementation(async params => ({ data: [record], meta: { current_page: params.page, last_page: 2, total: 16 } }))
  api.getAdminInternship.mockResolvedValue(record)
  api.getAdminInternshipStatistics.mockResolvedValue({ total: 8, pending_review: 2, active: 1, completed: 1 })
})
afterEach(() => { routers.splice(0).forEach(memory => memory.dispose()) })

it('adds admin navigation and lists only read-only oversight fields', async () => {
  page()
  expect(await screen.findByText('Arta Student')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Praktikat', exact: true })).toHaveAttribute('href', '/admin/internships')
  expect(screen.getByText('Example Company')).toBeInTheDocument()
  expect(screen.getByText('Pa caktuar')).toBeInTheDocument()
  expect(screen.getByText('Dorëzuar')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Detajet' })).toHaveAttribute('href', '/admin/internships/12')
  expect(screen.queryByRole('button', { name: /Mirato|Ndrysho|Refuzo|Fshi/ })).not.toBeInTheDocument()
})
it('uses server search, status filters and pagination without per-keystroke requests', async () => {
  page()
  await screen.findByText('Arta Student')
  fireEvent.change(screen.getByLabelText('Kërko student ose kompani'), { target: { value: 'Example' } })
  expect(api.listAdminInternships).toHaveBeenCalledTimes(1)
  fireEvent.click(screen.getByRole('button', { name: 'Kërko', exact: true }))
  await waitFor(() => expect(api.listAdminInternships).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Example', page: 1 }), expect.anything()))
  fireEvent.change(screen.getByLabelText('Statusi i praktikës'), { target: { value: 'ACTIVE' } })
  await waitFor(() => expect(api.listAdminInternships).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'ACTIVE', search: 'Example', page: 1 }), expect.anything()))
  await screen.findByText('Arta Student')
  fireEvent.click(screen.getByRole('button', { name: 'Pas' }))
  await waitFor(() => expect(api.listAdminInternships).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'ACTIVE', page: 2 }), expect.anything()))
})
it('renders actual overview counts and documents pending statuses', async () => {
  page('/admin')
  await screen.findByText('Gjithsej praktika')
  expect(screen.getByText('Gjithsej praktika').parentElement).toHaveTextContent('8')
  expect(screen.getByText('Në pritje të shqyrtimit').parentElement).toHaveTextContent('2')
  expect(screen.getByText(/SUBMITTED.*UNDER_REVIEW/)).toBeInTheDocument()
})
it('shows safe read-only details and back navigation', async () => {
  page('/admin/internships/12')
  expect(await screen.findByText('Public description')).toBeInTheDocument()
  expect(api.getAdminInternship).toHaveBeenCalledWith('12', expect.anything())
  expect(screen.getByRole('link', { name: /Të gjitha praktikat/ })).toHaveAttribute('href', '/admin/internships')
  expect(screen.queryByRole('button', { name: /Mirato|Ndrysho|Refuzo|Fshi/ })).not.toBeInTheDocument()
  expect(screen.queryByRole('link', { name: /Mesazhet|Vlerësimi|Shkarko/ })).not.toBeInTheDocument()
})
it('does not invent zero statistics on failure and supports retry', async () => {
  api.getAdminInternshipStatistics.mockRejectedValueOnce({ status: 500 })
  api.getAdminInternshipStatistics.mockResolvedValue({ total: 0, pending_review: 0, active: 0, completed: 0 })
  page('/admin')
  expect(await screen.findByRole('alert')).toHaveTextContent('Të dhënat nuk u ngarkuan.')
  expect(screen.queryByText('Gjithsej praktika')).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  await screen.findByText('Gjithsej praktika')
  expect(screen.getByText('Gjithsej praktika').parentElement).toHaveTextContent('0')
})
it.each(['STUDENT', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR'])('denies the actual admin routes to %s', async role => {
  const memory = page('/admin/internships/12', { ...admin, role })
  const landing = { STUDENT: '/student/internships', COMPANY_SUPERVISOR: '/supervisor/internships', ACADEMIC_COORDINATOR: '/coordinator/internships' }[role]
  await waitFor(() => expect(memory.state.location.pathname).toBe(landing))
  expect(api.getAdminInternship).not.toHaveBeenCalled()
  expect(api.listAdminInternships).not.toHaveBeenCalled()
})
it('redirects guests before fetching internship data', async () => {
  const memory = page('/admin/internships', null)
  await waitFor(() => expect(memory.state.location.pathname).toBe('/login'))
  expect(api.listAdminInternships).not.toHaveBeenCalled()
})
it('handles empty lists and list retry', async () => {
  api.listAdminInternships.mockRejectedValueOnce({ status: 500 })
  api.listAdminInternships.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
  page()
  expect(await screen.findByRole('alert')).toHaveTextContent('Të dhënat nuk u ngarkuan.')
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  expect(await screen.findByText('Nuk u gjet asnjë praktikë.')).toBeInTheDocument()
})
it('handles missing details without exposing stale data', async () => {
  api.getAdminInternship.mockRejectedValue({ status: 404 })
  page('/admin/internships/999')
  expect(await screen.findByRole('alert')).toHaveTextContent('Praktika nuk u gjet.')
  expect(screen.queryByText('Public description')).not.toBeInTheDocument()
})
