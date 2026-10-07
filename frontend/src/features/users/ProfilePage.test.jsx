import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getProfile, updateProfile } from './api/usersApi'

vi.mock('./api/usersApi', () => ({ getProfile: vi.fn(), updateProfile: vi.fn(), errorMessage: () => 'Veprimi nuk u krye.' }))

const student = {
  id: 7, first_name: 'Diella', last_name: 'Hoxha', email: 'diella@example.test', phone: '123',
  role: 'STUDENT', is_active: true, profile: { student_number: 'STU-7', study_program: 'Computing', study_year: 2 },
}
const routers = []
const refreshUser = vi.fn()

function renderProfile(path = '/profile', user = student) {
  const memoryRouter = createMemoryRouter(router.routes, { initialEntries: [path] })
  routers.push(memoryRouter)
  render(<AuthContext.Provider value={{ user, isAuthenticated: true, isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memoryRouter} /></AuthContext.Provider>)
  return memoryRouter
}

beforeEach(() => {
  vi.clearAllMocks()
  getProfile.mockResolvedValue(student)
  refreshUser.mockResolvedValue(student)
})
afterEach(() => { routers.splice(0).forEach((memoryRouter) => memoryRouter.dispose()) })

describe('self-service profile', () => {
  it('loads safe data and displays institutional fields as read-only', async () => {
    renderProfile()
    expect(await screen.findByDisplayValue('Diella')).toBeInTheDocument()
    expect(screen.getByText('STU-7')).toBeInTheDocument()
    expect(screen.getByText('Computing')).toBeInTheDocument()
    expect(screen.queryByRole('textbox', { name: /Email/ })).not.toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ruaj ndryshimet' })).toBeDisabled()
  })

  it('sends only changed permitted fields, refreshes AuthProvider and shows success', async () => {
    updateProfile.mockResolvedValue({ ...student, first_name: 'Updated' })
    renderProfile()
    const input = await screen.findByLabelText('Emri *')
    fireEvent.change(input, { target: { value: 'Updated' } })
    fireEvent.click(screen.getByRole('button', { name: 'Ruaj ndryshimet' }))
    await waitFor(() => expect(updateProfile).toHaveBeenCalledWith({ first_name: 'Updated' }))
    expect(await screen.findByText('Profili u përditësua me sukses.')).toBeInTheDocument()
    expect(refreshUser).toHaveBeenCalledTimes(1)
    expect(screen.getByRole('button', { name: 'Ruaj ndryshimet' })).toBeDisabled()
  })

  it('displays backend validation errors and keeps the draft for correction', async () => {
    updateProfile.mockRejectedValue({ type: 'validation', status: 422, validationErrors: { first_name: ['Invalid name.'] } })
    renderProfile()
    fireEvent.change(await screen.findByLabelText('Emri *'), { target: { value: 'Rejected' } })
    fireEvent.click(screen.getByRole('button', { name: 'Ruaj ndryshimet' }))
    expect(await screen.findByText('Invalid name.')).toBeInTheDocument()
    expect(screen.getByDisplayValue('Rejected')).toBeInTheDocument()
    expect(refreshUser).not.toHaveBeenCalled()
  })

  it('resets unsaved changes and provides reachable navigation from the existing session page', async () => {
    const memoryRouter = renderProfile('/session')
    fireEvent.click(screen.getByRole('link', { name: 'Profili im' }))
    expect(memoryRouter.state.location.pathname).toBe('/profile')
    fireEvent.change(await screen.findByLabelText('Emri *'), { target: { value: 'Draft' } })
    fireEvent.click(screen.getByRole('button', { name: 'Anulo ndryshimet' }))
    expect(screen.getByDisplayValue('Diella')).toBeInTheDocument()
    expect(updateProfile).not.toHaveBeenCalled()
  })

  it('uses the existing Admin layout and exposes its Profile navigation', async () => {
    const admin = { ...student, role: 'ADMIN', profile: undefined }
    getProfile.mockResolvedValue(admin)
    renderProfile('/profile', admin)
    await screen.findByDisplayValue('Diella')
    expect(screen.getByRole('navigation', { name: 'Navigimi i administratorit' })).toBeInTheDocument()
    expect(screen.getByRole('link', { name: 'Profili im' })).toHaveAttribute('href', '/profile')
  })
})
