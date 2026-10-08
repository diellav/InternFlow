import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getCompany, listCompanies } from './api/companiesApi'

vi.mock('./api/companiesApi', () => ({ listCompanies: vi.fn(), getCompany: vi.fn(), verifyCompany: vi.fn(), companyErrorMessage: (error) => error.message }))

const admin = { id: 99, first_name: 'Admin', last_name: 'Test', role: 'ADMIN' }
const company = {
  id: 4, name: 'Acme', industry: 'Technology', address: 'Main Street', email: 'acme@example.test',
  phone: null, website: null, is_active: true, verification_status: 'PENDING',
  created_at: '2026-01-01T12:00:00Z', verified_at: null, verified_by: null, verification_note: null,
  supervisors: [{ user_id: 7, first_name: 'Mentor', last_name: 'Test', email: 'mentor@example.test', is_active: false, job_title: 'Engineer', verification_status: 'APPROVED' }],
}
const routers = []

function renderPage(path, user = admin) {
  const memoryRouter = createMemoryRouter(router.routes, { initialEntries: [path] })
  routers.push(memoryRouter)
  render(<AuthContext.Provider value={{ user, isAuthenticated: Boolean(user), isLoading: false, logout: vi.fn() }}><RouterProvider router={memoryRouter} /></AuthContext.Provider>)
  return memoryRouter
}

beforeEach(() => {
  vi.clearAllMocks()
  listCompanies.mockResolvedValue({ data: [company], meta: { current_page: 1, last_page: 1, total: 1, per_page: 15 } })
  getCompany.mockResolvedValue(company)
})
afterEach(() => { routers.splice(0).forEach((memoryRouter) => memoryRouter.dispose()) })

describe('Admin companies', () => {
  it('loads real API-shaped data, sends filters and navigates to details', async () => {
    const memoryRouter = renderPage('/admin/companies')
    expect(await screen.findByText('acme@example.test')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Kompanitë', exact: true })).toHaveAttribute('href', '/admin/companies')
    fireEvent.change(screen.getByLabelText('Kërko kompani'), { target: { value: 'Acme' } })
    fireEvent.change(screen.getByLabelText('Verifikimi'), { target: { value: 'PENDING' } })
    await waitFor(() => expect(listCompanies).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Acme', verification_status: 'PENDING', page: 1 }), expect.any(AbortSignal)))
    fireEvent.click(await screen.findByRole('link', { name: 'Detajet', exact: true }))
    await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/admin/companies/4'))
    expect(await screen.findByText('Main Street')).toBeInTheDocument()
    expect(getCompany).toHaveBeenCalledWith('4', expect.any(AbortSignal))
  })

  it('uses server-side pagination and resets the page when filters change', async () => {
    listCompanies.mockResolvedValue({ data: [company], meta: { current_page: 1, last_page: 2, total: 16, per_page: 15 } })
    renderPage('/admin/companies')
    await screen.findByText('acme@example.test')
    fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
    await waitFor(() => expect(listCompanies).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2, per_page: 15 }), expect.any(AbortSignal)))
    fireEvent.change(screen.getByLabelText('Verifikimi'), { target: { value: 'REJECTED' } })
    await waitFor(() => expect(listCompanies).toHaveBeenLastCalledWith(expect.objectContaining({ page: 1, verification_status: 'REJECTED' }), expect.any(AbortSignal)))
  })

  it('renders company and supervisor states separately without mutation actions', async () => {
    getCompany.mockResolvedValue({ ...company, verification_status: 'REJECTED' })
    renderPage('/admin/companies/4')
    expect(await screen.findByText('Main Street')).toBeInTheDocument()
    const supervisors = screen.getByRole('region', { name: 'Mbikëqyrësit e kompanisë' })
    expect(within(supervisors).getByText('Joaktive')).toBeInTheDocument()
    expect(within(supervisors).getByText('Miratuar')).toBeInTheDocument()
    expect(within(supervisors).getByRole('link', { name: 'Detajet e përdoruesit' })).toHaveAttribute('href', '/admin/users/7')
    expect(screen.getAllByText('Refuzuar')).toHaveLength(2)
    expect(screen.queryByRole('button', { name: /Mirato|Refuzo|Aktivizo/ })).not.toBeInTheDocument()
  })

  it('handles empty data and failed details requests', async () => {
    listCompanies.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 15 } })
    const memoryRouter = renderPage('/admin/companies')
    expect(await screen.findByText('Nuk u gjet asnjë kompani')).toBeInTheDocument()
    getCompany.mockRejectedValue({ status: 404, message: 'Kompania nuk u gjet.' })
    await memoryRouter.navigate('/admin/companies/999')
    expect(await screen.findByRole('alert')).toHaveTextContent('Kompania nuk u gjet.')
    expect(screen.getByRole('link', { name: 'Kthehu te kompanitë' })).toHaveAttribute('href', '/admin/companies')
  })

  it('protects the company routes from non-Admins', async () => {
    const memoryRouter = renderPage('/admin/companies', { ...admin, role: 'STUDENT' })
    await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/session'))
    expect(listCompanies).not.toHaveBeenCalled()
  })
})
