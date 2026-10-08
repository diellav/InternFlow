import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getSupervisor, listSupervisors, verifySupervisor } from './api/supervisorsApi'

vi.mock('./api/supervisorsApi', async (importOriginal) => ({ ...await importOriginal(), getSupervisor: vi.fn(), listSupervisors: vi.fn(), verifySupervisor: vi.fn() }))

const admin = { id: 99, first_name: 'Admin', last_name: 'Test', role: 'ADMIN' }
const supervisor = {
  id: 7, first_name: 'Ada', last_name: 'Mentor', email: 'ada@example.test', phone: '123', is_active: true,
  profile: { job_title: 'Engineer', verification_status: 'PENDING', reviewed_by: null, reviewed_at: null, review_comment: null,
    company: { id: 4, name: 'Acme', is_active: true, verification_status: 'PENDING' } },
}
const routers = []

function renderPage(path = '/admin/supervisors/7') {
  const memoryRouter = createMemoryRouter(router.routes, { initialEntries: [path] })
  routers.push(memoryRouter)
  render(<AuthContext.Provider value={{ user: admin, isAuthenticated: true, isLoading: false, logout: vi.fn() }}><RouterProvider router={memoryRouter} /></AuthContext.Provider>)
  return memoryRouter
}

beforeEach(() => {
  vi.resetAllMocks()
  getSupervisor.mockResolvedValue(supervisor)
  listSupervisors.mockResolvedValue({ data: [supervisor], meta: { current_page: 1, last_page: 2, total: 16, per_page: 15 } })
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
})
afterEach(() => { routers.splice(0).forEach((memoryRouter) => memoryRouter.dispose()) })

describe('Admin supervisors', () => {
  it('loads API data, sends filters and pagination, and opens supervisor details', async () => {
    const memoryRouter = renderPage('/admin/supervisors')
    expect(await screen.findByText('ada@example.test')).toBeInTheDocument()
    fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
    await waitFor(() => expect(listSupervisors).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }), expect.any(AbortSignal)))
    fireEvent.change(screen.getByLabelText('Kërko mbikëqyrës'), { target: { value: 'Ada' } })
    fireEvent.change(screen.getByLabelText('Verifikimi i mbikëqyrësit'), { target: { value: 'PENDING' } })
    fireEvent.change(screen.getByLabelText('ID e kompanisë (opsionale)'), { target: { value: '4' } })
    await waitFor(() => expect(listSupervisors).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Ada', verification_status: 'PENDING', company_id: '4', page: 1 }), expect.any(AbortSignal)))
    fireEvent.click(await screen.findByRole('link', { name: 'Detajet', exact: true }))
    await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/admin/supervisors/7'))
    expect(await screen.findByText('Engineer')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Acme' })).toHaveAttribute('href', '/admin/companies/4')
  })

  it('confirms approval without granting access when the company is still pending', async () => {
    let finish
    verifySupervisor.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Mirato mbikëqyrësin' }))
    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByText('Ada Mentor')).toBeInTheDocument()
    expect(verifySupervisor).not.toHaveBeenCalled()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo' }))
    expect(within(dialog).getByRole('button', { name: 'Duke ruajtur…' })).toBeDisabled()
    expect(verifySupervisor).toHaveBeenCalledTimes(1)
    expect(verifySupervisor).toHaveBeenCalledWith(7, { decision: 'APPROVED' })
    finish({ ...supervisor, profile: { ...supervisor.profile, verification_status: 'APPROVED', reviewed_by: 99 } })
    expect(await screen.findByText('Mbikëqyrësi u miratua me sukses.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Mirato mbikëqyrësin' })).not.toBeInTheDocument()
    expect(screen.getByRole('note')).toHaveTextContent('Kompania nuk është miratuar.')
    expect(screen.getByText('Në pritje')).toBeInTheDocument()
  })

  it('requires a rejection reason, handles validation errors and shows the saved reason', async () => {
    verifySupervisor.mockRejectedValueOnce({ type: 'validation', status: 422, validationErrors: { reason: ['Invalid reason.'] } })
      .mockResolvedValueOnce({ ...supervisor, profile: { ...supervisor.profile, verification_status: 'REJECTED', review_comment: 'Cannot verify.' } })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Refuzo mbikëqyrësin' }))
    const dialog = screen.getByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo' }))
    expect(verifySupervisor).not.toHaveBeenCalled()
    fireEvent.change(within(dialog).getByLabelText('Arsyeja e refuzimit *'), { target: { value: '  Cannot verify.  ' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo' }))
    expect(await within(dialog).findByText('Invalid reason.')).toBeInTheDocument()
    expect(within(dialog).getByLabelText('Arsyeja e refuzimit *')).toHaveValue('  Cannot verify.  ')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo' }))
    expect(await screen.findByText('Mbikëqyrësi u refuzua me sukses.')).toBeInTheDocument()
    expect(verifySupervisor).toHaveBeenLastCalledWith(7, { decision: 'REJECTED', reason: 'Cannot verify.' })
    expect(screen.getByText('Cannot verify.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Refuzo mbikëqyrësin' })).not.toBeInTheDocument()
  })

  it('reloads after conflict and hides reviewed actions', async () => {
    verifySupervisor.mockRejectedValue({ status: 409 })
    renderPage()
    fireEvent.click(await screen.findByRole('button', { name: 'Mirato mbikëqyrësin' }))
    getSupervisor.mockResolvedValue({ ...supervisor, profile: { ...supervisor.profile, verification_status: 'REJECTED' } })
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Konfirmo' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Mbikëqyrësi nuk është në pritje të shqyrtimit')
    await waitFor(() => expect(getSupervisor).toHaveBeenCalledTimes(2))
    expect(await screen.findAllByText('Refuzuar')).toHaveLength(2)
    expect(screen.queryByRole('button', { name: 'Mirato mbikëqyrësin' })).not.toBeInTheDocument()
  })

  it('explains restrictions for an approved company with a pending supervisor and for inactive accounts', async () => {
    getSupervisor.mockResolvedValue({ ...supervisor, is_active: false, profile: { ...supervisor.profile, company: { ...supervisor.profile.company, verification_status: 'APPROVED' } } })
    renderPage()
    await screen.findByText('Engineer')
    expect(screen.getByRole('note')).toHaveTextContent('Mbikëqyrësi nuk është miratuar.')
    expect(screen.getByRole('note')).toHaveTextContent('Llogaria është joaktive.')
    expect(screen.getByText('Joaktive')).toBeInTheDocument()
  })
})
