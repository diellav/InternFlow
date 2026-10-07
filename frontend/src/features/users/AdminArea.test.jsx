import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getUser, listUsers, saveCoordinator, updateActivation } from './api/usersApi'

vi.mock('./api/usersApi', () => ({ listUsers: vi.fn(), getUser: vi.fn(), saveCoordinator: vi.fn(), updateActivation: vi.fn(), errorMessage: (error) => error.message ?? 'Veprimi nuk u krye.' }))

const admin = { id: 99, first_name: 'Admin', last_name: 'Test', role: 'ADMIN' }
const student = { id: 7, first_name: 'Diella', last_name: 'Hoxha', email: 'diella@example.test', is_active: true, role: 'STUDENT' }
const coordinator = { ...student, role: 'ACADEMIC_COORDINATOR', profile: { academic_unit: 'Engineering' } }
const pagination = { current_page: 1, last_page: 1, total: 1, per_page: 15 }
const routers = []

function renderPage(path, user = admin) {
  const memoryRouter = createMemoryRouter(router.routes, { initialEntries: [path] })
  routers.push(memoryRouter)
  render(<AuthContext.Provider value={{ user, isAuthenticated: Boolean(user), isLoading: false, logout: vi.fn() }}><RouterProvider router={memoryRouter} /></AuthContext.Provider>)
  return memoryRouter
}

beforeEach(() => {
  vi.clearAllMocks()
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
  listUsers.mockResolvedValue({ data: [student], meta: pagination, links: {} })
  getUser.mockResolvedValue(coordinator)
})
afterEach(() => { routers.splice(0).forEach((memoryRouter) => memoryRouter.dispose()) })

describe('Admin area', () => {
  it('protects actual Admin routes and admits an Admin', async () => {
    const memoryRouter = renderPage('/admin', { ...student, role: 'STUDENT' })
    await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/session'))
    expect(screen.queryByText('Llogaritë e sistemit')).not.toBeInTheDocument()
  })

  it('renders the Admin landing page without invented statistics', async () => {
    renderPage('/admin')
    expect(await screen.findByText('Mirë se vini, Admin')).toBeInTheDocument()
    expect(screen.getByRole('link', { name: /Shtoni një koordinator/ })).toHaveAttribute('href', '/admin/academic-coordinators/new')
  })

  it('loads users and sends server-side filters', async () => {
    renderPage('/admin/users')
    expect(await screen.findByText('diella@example.test')).toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Kërko përdorues'), { target: { value: 'Diella' } })
    fireEvent.change(screen.getByLabelText('Roli'), { target: { value: 'STUDENT' } })
    await waitFor(() => expect(listUsers).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Diella', role: 'STUDENT', page: 1 }), expect.any(AbortSignal)))
  })

  it('confirms activation before changing state, prevents duplicate submission and refreshes on success', async () => {
    let finish
    updateActivation.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
    renderPage('/admin/users')
    fireEvent.click(await screen.findByRole('button', { name: 'Çaktivizo' }))
    expect(updateActivation).not.toHaveBeenCalled()
    const dialog = screen.getByRole('dialog')
    fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo' }))
    expect(within(dialog).getByRole('button', { name: 'Duke ruajtur…' })).toBeDisabled()
    expect(updateActivation).toHaveBeenCalledWith(7, false)
    listUsers.mockResolvedValue({ data: [{ ...student, is_active: false }], meta: pagination, links: {} })
    finish({ ...student, is_active: false })
    expect(await screen.findByText('Statusi i llogarisë u përditësua.')).toBeInTheDocument()
    expect(await screen.findByRole('button', { name: 'Aktivizo' })).toBeInTheDocument()
  })

  it('keeps the confirmation open when the backend blocks activation', async () => {
    updateActivation.mockRejectedValue({ message: 'The last active Admin cannot be deactivated.' })
    renderPage('/admin/users')
    fireEvent.click(await screen.findByRole('button', { name: 'Çaktivizo' }))
    fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Konfirmo' }))
    expect(await screen.findByRole('alert')).toHaveTextContent('The last active Admin cannot be deactivated.')
    expect(screen.getByRole('dialog')).toBeInTheDocument()
  })

  it('submits the create form and navigates to safe coordinator details', async () => {
    saveCoordinator.mockResolvedValue(coordinator)
    const memoryRouter = renderPage('/admin/academic-coordinators/new')
    fireEvent.change(screen.getByLabelText('Emri *'), { target: { value: 'Diella' } })
    fireEvent.change(screen.getByLabelText('Mbiemri *'), { target: { value: 'Hoxha' } })
    fireEvent.change(screen.getByLabelText('Email *'), { target: { value: 'diella@example.test' } })
    fireEvent.change(screen.getByLabelText('Fjalëkalimi fillestar *'), { target: { value: 'Coordinator123' } })
    fireEvent.click(screen.getByRole('button', { name: 'Krijo koordinator' }))
    await waitFor(() => expect(saveCoordinator).toHaveBeenCalledWith(undefined, expect.objectContaining({ password: 'Coordinator123', email: 'diella@example.test' })))
    await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/admin/users/7'))
    expect(await screen.findByText('Llogaria e koordinatorit u krijua me sukses.')).toBeInTheDocument()
  })

  it('edits a coordinator without submitting password, role or activation', async () => {
    saveCoordinator.mockResolvedValue(coordinator)
    renderPage('/admin/academic-coordinators/7/edit')
    await screen.findByDisplayValue('Engineering')
    expect(screen.queryByLabelText('Fjalëkalimi fillestar *')).not.toBeInTheDocument()
    fireEvent.change(screen.getByLabelText('Njësia akademike (opsionale)'), { target: { value: 'Computing' } })
    fireEvent.click(screen.getByRole('button', { name: 'Ruaj ndryshimet' }))
    await waitFor(() => expect(saveCoordinator).toHaveBeenCalledWith('7', expect.objectContaining({ academic_unit: 'Computing' })))
    const payload = saveCoordinator.mock.calls[0][1]
    expect(payload).not.toHaveProperty('password')
    expect(payload).not.toHaveProperty('role')
    expect(payload).not.toHaveProperty('is_active')
  })
})
