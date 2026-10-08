import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getVerificationApplication, saveVerificationApplication, resubmitVerificationApplication } from './api/verificationApplicationApi'

vi.mock('./api/verificationApplicationApi', () => ({ getVerificationApplication: vi.fn(), saveVerificationApplication: vi.fn(), resubmitVerificationApplication: vi.fn() }))

const supervisorFields = ['first_name', 'last_name', 'phone', 'job_title']
const companyFields = ['name', 'industry', 'address', 'email', 'phone', 'website']
const rejected = {
  supervisor: { first_name: 'Ada', last_name: 'Mentor', email: 'ada@example.test', phone: null, is_active: true, job_title: 'Engineer', verification_status: 'REJECTED', rejection_reason: 'Supervisor reason' },
  company: { name: 'Acme', industry: 'IT', address: null, email: null, phone: null, website: null, is_active: true, verification_status: 'REJECTED', rejection_reason: 'Company reason' },
  can_resubmit_supervisor: true, can_resubmit_company: true,
  editable_supervisor_fields: supervisorFields, editable_company_fields: companyFields, company_editing_limitation: null,
}
const pending = { ...rejected,
  supervisor: { ...rejected.supervisor, verification_status: 'PENDING', rejection_reason: null },
  company: { ...rejected.company, verification_status: 'PENDING', rejection_reason: null },
  can_resubmit_supervisor: false, can_resubmit_company: false, editable_supervisor_fields: [], editable_company_fields: [],
}
const routers = []
const refreshUser = vi.fn()

function renderPage() {
  const memoryRouter = createMemoryRouter(router.routes, { initialEntries: ['/supervisor/verification'] })
  routers.push(memoryRouter)
  render(<AuthContext.Provider value={{ user: { ...rejected.supervisor, role: 'COMPANY_SUPERVISOR' }, isAuthenticated: true, isLoading: false, refreshUser }}><RouterProvider router={memoryRouter} /></AuthContext.Provider>)
}

beforeEach(() => {
  vi.resetAllMocks()
  getVerificationApplication.mockResolvedValue(rejected)
  refreshUser.mockResolvedValue(rejected.supervisor)
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
})
afterEach(() => routers.splice(0).forEach((memoryRouter) => memoryRouter.dispose()))

it('shows both rejection reasons, saves rejected fields without resubmitting, and refreshes identity', async () => {
  saveVerificationApplication.mockResolvedValue({ ...rejected, supervisor: { ...rejected.supervisor, first_name: 'Updated' } })
  renderPage()
  expect(await screen.findByText('Company reason')).toBeInTheDocument()
  expect(screen.getByText('Supervisor reason')).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Emri *'), { target: { value: 'Updated' } })
  expect(screen.getByRole('button', { name: 'Ridërgo për verifikim' })).toBeDisabled()
  const section = screen.getByRole('region', { name: 'Verifikimi i mbikëqyrësit' })
  fireEvent.click(within(section).getByRole('button', { name: 'Ruaj ndryshimet' }))
  await waitFor(() => expect(saveVerificationApplication).toHaveBeenCalledWith({ supervisor: { first_name: 'Updated', last_name: 'Mentor', phone: null, job_title: 'Engineer' } }))
  expect(await screen.findByText(/Ndryshimet u ruajtën/)).toBeInTheDocument()
  expect(refreshUser).toHaveBeenCalledTimes(1)
  expect(resubmitVerificationApplication).not.toHaveBeenCalled()
  expect(screen.getByText('Supervisor reason')).toBeInTheDocument()
})

it('confirms combined resubmission, blocks duplicates, and removes actions only after the response', async () => {
  let finish
  resubmitVerificationApplication.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Ridërgo për verifikim' }))
  const dialog = screen.getByRole('dialog')
  expect(within(dialog).getByText('Kompania dhe mbikëqyrësi do të kthehen në PENDING.')).toBeInTheDocument()
  expect(resubmitVerificationApplication).not.toHaveBeenCalled()
  fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo ridërgimin' }))
  expect(resubmitVerificationApplication).toHaveBeenCalledWith(['supervisor', 'company'])
  expect(within(dialog).getByRole('button', { name: 'Duke ridërguar…' })).toBeDisabled()
  expect(screen.getByText('Company reason')).toBeInTheDocument()
  finish(pending)
  expect(await screen.findByText(/Aplikimi u ridërgua/)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Ridërgo për verifikim' })).not.toBeInTheDocument()
  expect(screen.queryByText('Company reason')).not.toBeInTheDocument()
  expect(resubmitVerificationApplication).toHaveBeenCalledTimes(1)
})

it.each(['APPROVED', 'PENDING'])('resubmits only the rejected supervisor and leaves the %s company read-only', async (status) => {
  const data = { ...rejected, company: { ...rejected.company, verification_status: status, rejection_reason: null }, can_resubmit_company: false, editable_company_fields: [] }
  getVerificationApplication.mockResolvedValue(data)
  resubmitVerificationApplication.mockResolvedValue({ ...data, supervisor: pending.supervisor, can_resubmit_supervisor: false, editable_supervisor_fields: [] })
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Ridërgo për verifikim' }))
  expect(screen.queryByLabelText('Emri i kompanisë *')).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo ridërgimin' }))
  await waitFor(() => expect(resubmitVerificationApplication).toHaveBeenCalledWith(['supervisor']))
  expect(await screen.findByText(/Aplikimi u ridërgua/)).toBeInTheDocument()
})

it.each(['PENDING', 'APPROVED'])('shows no edits or resubmission when both applications are %s', async (status) => {
  getVerificationApplication.mockResolvedValue({ ...pending, supervisor: { ...pending.supervisor, verification_status: status }, company: { ...pending.company, verification_status: status } })
  renderPage()
  expect(await screen.findByText('Acme')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Ridërgo për verifikim' })).not.toBeInTheDocument()
  expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
})

it('clears an old loading error after a successful retry', async () => {
  getVerificationApplication.mockRejectedValueOnce({ type: 'network' })
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Provo përsëri' }))
  expect(await screen.findByText('Company reason')).toBeInTheDocument()
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
})

it('resubmits only the rejected company without changing the approved supervisor', async () => {
  const data = { ...rejected, supervisor: { ...rejected.supervisor, verification_status: 'APPROVED', rejection_reason: null }, can_resubmit_supervisor: false, editable_supervisor_fields: [] }
  getVerificationApplication.mockResolvedValue(data)
  resubmitVerificationApplication.mockResolvedValue({ ...data, company: pending.company, can_resubmit_company: false, editable_company_fields: [] })
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Ridërgo për verifikim' }))
  expect(screen.queryByLabelText('Emri *')).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo ridërgimin' }))
  await waitFor(() => expect(resubmitVerificationApplication).toHaveBeenCalledWith(['company']))
  expect(await screen.findByText(/Aplikimi u ridërgua/)).toBeInTheDocument()
})

it('does not expose shared company editing and allows resetting a supervisor draft', async () => {
  getVerificationApplication.mockResolvedValue({ ...rejected, can_resubmit_company: false, editable_company_fields: [], company_editing_limitation: 'Shared company' })
  renderPage()
  expect(await screen.findByText(/Kompania ka disa mbikëqyrës/)).toBeInTheDocument()
  expect(screen.queryByLabelText('Emri i kompanisë *')).not.toBeInTheDocument()
  const section = screen.getByRole('region', { name: 'Verifikimi i mbikëqyrësit' })
  fireEvent.change(screen.getByLabelText('Emri *'), { target: { value: 'Draft' } })
  fireEvent.click(within(section).getByRole('button', { name: 'Anulo ndryshimet' }))
  expect(screen.getByLabelText('Emri *')).toHaveValue('Ada')
  expect(saveVerificationApplication).not.toHaveBeenCalled()
})

it('keeps drafts on backend validation errors and reloads current state on conflict', async () => {
  saveVerificationApplication.mockRejectedValueOnce({ status: 422, validationErrors: { 'supervisor.first_name': ['Invalid name'] } })
  renderPage()
  fireEvent.change(await screen.findByLabelText('Emri *'), { target: { value: 'Draft' } })
  const section = screen.getByRole('region', { name: 'Verifikimi i mbikëqyrësit' })
  fireEvent.click(within(section).getByRole('button', { name: 'Ruaj ndryshimet' }))
  expect(await screen.findByText('Invalid name')).toBeInTheDocument()
  expect(screen.getByLabelText('Emri *')).toHaveValue('Draft')
  fireEvent.click(within(section).getByRole('button', { name: 'Anulo ndryshimet' }))
  resubmitVerificationApplication.mockRejectedValueOnce({ status: 409 })
  getVerificationApplication.mockResolvedValueOnce(pending)
  fireEvent.click(screen.getByRole('button', { name: 'Ridërgo për verifikim' }))
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo ridërgimin' }))
  await waitFor(() => expect(getVerificationApplication).toHaveBeenCalledTimes(2))
  await waitFor(() => {
    expect(screen.getByRole('heading', { name: 'Aplikimi për verifikim', exact: true })).toBeInTheDocument()
    expect(screen.getByText(/Gjendja e aplikimit ka ndryshuar/)).toBeInTheDocument()
    expect(screen.queryByRole('button', { name: 'Ridërgo për verifikim' })).not.toBeInTheDocument()
  })
})
