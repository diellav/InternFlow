import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { MemoryRouter } from 'react-router-dom'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { getSupervisorRegistrationCompanies, registerSupervisor } from '../api/authApi'
import { SupervisorRegistrationPage } from './SupervisorRegistrationPage'

vi.mock('../api/authApi', () => ({
  getSupervisorRegistrationCompanies: vi.fn(),
  registerSupervisor: vi.fn(),
}))

function renderPage() {
  return render(<MemoryRouter><SupervisorRegistrationPage /></MemoryRouter>)
}

function fillCommonFields() {
  fireEvent.change(screen.getByLabelText('Emri'), { target: { value: 'Test' } })
  fireEvent.change(screen.getByLabelText('Mbiemri'), { target: { value: 'Supervisor' } })
  fireEvent.change(screen.getByLabelText('Email'), { target: { value: 'supervisor@example.com' } })
  fireEvent.change(screen.getByLabelText('Fjalëkalimi'), { target: { value: 'Supervisor123' } })
  fireEvent.change(screen.getByLabelText('Konfirmo fjalëkalimin'), { target: { value: 'Supervisor123' } })
}

describe('SupervisorRegistrationPage payload boundaries', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    getSupervisorRegistrationCompanies.mockResolvedValue([{ id: 7, name: 'Eligible Company' }])
    registerSupervisor.mockResolvedValue({})
  })

  it('submits only company_id after switching from new to existing mode', async () => {
    renderPage()
    await screen.findByRole('option', { name: 'Eligible Company' })
    fillCommonFields()

    fireEvent.click(screen.getByLabelText('Kompania ime nuk është ende e regjistruar'))
    fireEvent.change(screen.getByLabelText('Emri i kompanisë'), { target: { value: 'Stale Company' } })
    fireEvent.change(screen.getByLabelText('Industria (opsionale)'), { target: { value: 'Stale Industry' } })
    fireEvent.click(screen.getByLabelText('Kompania ime është e regjistruar në sistem'))
    fireEvent.change(screen.getByLabelText('Kompania'), { target: { value: '7' } })
    fireEvent.click(screen.getByRole('button', { name: 'Regjistrohu' }))

    await waitFor(() => expect(registerSupervisor).toHaveBeenCalledTimes(1))
    expect(registerSupervisor).toHaveBeenCalledWith(expect.objectContaining({
      company_mode: 'existing',
      company_id: 7,
    }))
    const payload = registerSupervisor.mock.calls[0][0]
    expect(payload).not.toHaveProperty('company_name')
    expect(payload).not.toHaveProperty('company_industry')
  })

  it('excludes company_id after switching from existing to new mode', async () => {
    renderPage()
    await screen.findByRole('option', { name: 'Eligible Company' })
    fillCommonFields()

    fireEvent.change(screen.getByLabelText('Kompania'), { target: { value: '7' } })
    fireEvent.click(screen.getByLabelText('Kompania ime nuk është ende e regjistruar'))
    fireEvent.change(screen.getByLabelText('Emri i kompanisë'), { target: { value: 'New Company' } })
    fireEvent.click(screen.getByRole('button', { name: 'Regjistrohu' }))

    await waitFor(() => expect(registerSupervisor).toHaveBeenCalledTimes(1))
    expect(registerSupervisor).toHaveBeenCalledWith(expect.objectContaining({
      company_mode: 'new',
      company_name: 'New Company',
    }))
    expect(registerSupervisor.mock.calls[0][0]).not.toHaveProperty('company_id')
  })
})
