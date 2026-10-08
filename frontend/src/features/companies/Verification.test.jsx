import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getCompany, verifyCompany } from './api/companiesApi'

vi.mock('./api/companiesApi', async (importOriginal) => ({ ...await importOriginal(), getCompany: vi.fn(), listCompanies: vi.fn(), verifyCompany: vi.fn() }))

const company = { id: 4, name: 'Acme', verification_status: 'PENDING', is_active: true, supervisors: [] }
const admin = { id: 99, first_name: 'Admin', last_name: 'Test', role: 'ADMIN' }
const routers = []

function renderDetails() {
  const memoryRouter = createMemoryRouter(router.routes, { initialEntries: ['/admin/companies/4'] })
  routers.push(memoryRouter)
  render(<AuthContext.Provider value={{ user: admin, isAuthenticated: true, isLoading: false, logout: vi.fn() }}><RouterProvider router={memoryRouter} /></AuthContext.Provider>)
}

beforeEach(() => {
  vi.clearAllMocks()
  getCompany.mockResolvedValue(company)
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
})
afterEach(() => { routers.splice(0).forEach((memoryRouter) => memoryRouter.dispose()) })

describe('company verification', () => {
  it('requires confirmation, prevents duplicates and only updates after approval succeeds', async () => {
    let finish
    verifyCompany.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
    renderDetails()
    fireEvent.click(await screen.findByRole('button', { name: 'Mirato kompaninë' }))
    expect(verifyCompany).not.toHaveBeenCalled()
    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByText('Acme')).toBeInTheDocument()
    expect(within(dialog).getByText(/Një aplikim i miratuar nuk mund të shqyrtohet përsëri/)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo' }))
    expect(verifyCompany).toHaveBeenCalledWith(4, { decision: 'APPROVED' })
    expect(verifyCompany).toHaveBeenCalledTimes(1)
    expect(within(dialog).getByRole('button', { name: 'Duke ruajtur…' })).toBeDisabled()
    expect(screen.getByRole('button', { name: 'Refuzo kompaninë' })).toBeDisabled()
    expect(screen.getAllByText('Në pritje')).toHaveLength(2)
    finish({ ...company, verification_status: 'APPROVED', verified_by: 99, verified_at: '2026-01-01T12:00:00Z' })
    expect(await screen.findByText('Kompania u miratua me sukses.')).toBeInTheDocument()
    expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Mirato kompaninë' })).not.toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Refuzo kompaninë' })).not.toBeInTheDocument()
    expect(screen.getAllByText('Miratuar')).toHaveLength(2)
  })

  it('requires a nonblank rejection reason and displays the saved reason after success', async () => {
    verifyCompany.mockResolvedValue({ ...company, verification_status: 'REJECTED', verification_note: 'Cannot verify.' })
    renderDetails()
    fireEvent.click(await screen.findByRole('button', { name: 'Refuzo kompaninë' }))
    const dialog = screen.getByRole('dialog')
    expect(within(dialog).getByText(/Një aplikim i refuzuar mund të shqyrtohet përsëri vetëm pas një ridërgimi të autorizuar/)).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo' }))
    expect(within(dialog).getByText('Shkruani arsyen e refuzimit.')).toBeInTheDocument()
    expect(verifyCompany).not.toHaveBeenCalled()
    fireEvent.change(within(dialog).getByLabelText('Arsyeja e refuzimit *'), { target: { value: '  Cannot verify.  ' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo' }))
    await waitFor(() => expect(verifyCompany).toHaveBeenCalledWith(4, { decision: 'REJECTED', reason: 'Cannot verify.' }))
    expect(await screen.findByText('Kompania u refuzua me sukses.')).toBeInTheDocument()
    expect(screen.getByText('Cannot verify.')).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Refuzo kompaninë' })).not.toBeInTheDocument()
  })

  it('refreshes company data and removes stale actions after a conflict', async () => {
    verifyCompany.mockRejectedValue({ status: 409 })
    renderDetails()
    fireEvent.click(await screen.findByRole('button', { name: 'Mirato kompaninë' }))
    getCompany.mockResolvedValue({ ...company, verification_status: 'APPROVED' })
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Konfirmo' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('Kompania nuk është në pritje të shqyrtimit.')
    await waitFor(() => expect(getCompany).toHaveBeenCalledTimes(2))
    expect(await screen.findAllByText('Miratuar')).toHaveLength(2)
    expect(screen.queryByRole('button', { name: 'Mirato kompaninë' })).not.toBeInTheDocument()
  })

  it('retains the rejection draft and shows field-level validation and network errors', async () => {
    verifyCompany.mockRejectedValueOnce({ type: 'validation', status: 422, validationErrors: { reason: ['Invalid reason.'] } })
      .mockRejectedValueOnce({ type: 'network', status: null })
    renderDetails()
    fireEvent.click(await screen.findByRole('button', { name: 'Refuzo kompaninë' }))
    const dialog = screen.getByRole('dialog')
    fireEvent.change(within(dialog).getByLabelText('Arsyeja e refuzimit *'), { target: { value: 'Draft reason' } })
    fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo' }))
    expect(await within(dialog).findByText('Invalid reason.')).toBeInTheDocument()
    expect(within(dialog).getByDisplayValue('Draft reason')).toBeInTheDocument()
    fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo' }))
    expect(await within(dialog).findByRole('alert')).toHaveTextContent('Të dhënat nuk mund të ngarkoheshin.')
    expect(within(dialog).getByDisplayValue('Draft reason')).toBeInTheDocument()
  })
})
