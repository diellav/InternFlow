import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { listAuditLogs } from './api/auditLogsApi'
import { auditDetails } from './auditLabels'

vi.mock('./api/auditLogsApi', async original => ({ ...await original(), listAuditLogs: vi.fn() }))
const admin = { id: 99, first_name: 'Admin', last_name: 'Test', role: 'ADMIN' }
const record = { id: 12, created_at: '2026-10-10T12:00:00Z', actor: { id: 1, first_name: 'Arta', last_name: 'Reader', email: 'arta@example.test', role: 'ACADEMIC_COORDINATOR' },
  action: 'internship.review_started', entity_type: 'internship', entity_id: 7, details: { before: { status: 'SUBMITTED' }, after: { status: 'UNDER_REVIEW' } } }
const routers = []
function page(user = admin, path = '/admin/audit-logs') {
  const memory = createMemoryRouter(router.routes, { initialEntries: [path] })
  routers.push(memory)
  render(<AuthContext.Provider value={{ user, isAuthenticated: Boolean(user), isLoading: false, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>)
  return memory
}
beforeEach(() => {
  vi.resetAllMocks()
  listAuditLogs.mockImplementation(async params => ({ data: [record], meta: { current_page: params.page, last_page: 2, total: 16, per_page: 15 } }))
})
afterEach(() => routers.splice(0).forEach(memory => memory.dispose()))

it('adds the actual admin route and sidebar entry and renders a read-only localized table', async () => {
  page()
  expect(await screen.findByText('Arta Reader')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Historiku i veprimeve', exact: true })).toHaveAttribute('href', '/admin/audit-logs')
  expect(screen.getByText('Shqyrtimi filloi', { selector: 'td' })).toBeInTheDocument()
  expect(screen.getByText('Praktikë #7')).toBeInTheDocument()
  expect(screen.getByText('Statusi: I dorëzuar → Në shqyrtim')).toBeInTheDocument()
  expect(screen.getByText('arta@example.test')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Krijo|Ndrysho|Fshi|Eksporto|Shkarko/ })).not.toBeInTheDocument()
})

it('uses server-side pagination and resets page when searching or filtering', async () => {
  page()
  await screen.findByText('Arta Reader')
  fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
  await waitFor(() => expect(listAuditLogs).toHaveBeenLastCalledWith({ page: 2, per_page: 15 }, expect.any(AbortSignal)))
  await screen.findByText('16 veprime · Faqja 2 nga 2')
  expect(screen.getByRole('button', { name: 'Pas', exact: true })).toBeDisabled()
  fireEvent.change(screen.getByLabelText('Kërko përdorues ose veprim'), { target: { value: '  Arta  ' } })
  fireEvent.click(screen.getByRole('button', { name: 'Kërko', exact: true }))
  await waitFor(() => expect(listAuditLogs).toHaveBeenLastCalledWith({ search: 'Arta', page: 1, per_page: 15 }, expect.any(AbortSignal)))
  fireEvent.change(screen.getByLabelText('Veprimi'), { target: { value: 'internship.review_started' } })
  fireEvent.change(screen.getByLabelText('Entiteti'), { target: { value: 'internship' } })
  fireEvent.change(screen.getByLabelText('Data nga'), { target: { value: '2026-10-01' } })
  fireEvent.change(screen.getByLabelText('Data deri'), { target: { value: '2026-10-10' } })
  await waitFor(() => expect(listAuditLogs).toHaveBeenLastCalledWith({ search: 'Arta', action: 'internship.review_started', entity_type: 'internship', date_from: '2026-10-01', date_to: '2026-10-10', page: 1, per_page: 15 }, expect.any(AbortSignal)))
  fireEvent.click(screen.getByRole('button', { name: 'Pastro filtrat' }))
  await waitFor(() => expect(listAuditLogs).toHaveBeenLastCalledWith({ page: 1, per_page: 15 }, expect.any(AbortSignal)))
  expect(screen.getByLabelText('Kërko përdorues ose veprim')).toHaveValue('')
})

it('supports previous-page navigation', async () => {
  page()
  await screen.findByText('Arta Reader')
  expect(screen.getByRole('button', { name: 'Para', exact: true })).toBeDisabled()
  fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
  await screen.findByText('16 veprime · Faqja 2 nga 2')
  fireEvent.click(screen.getByRole('button', { name: 'Para', exact: true }))
  await screen.findByText('16 veprime · Faqja 1 nga 2')
})

it('displays the account mutation history response with localized safe details', async () => {
  listAuditLogs.mockResolvedValue({ data: [{ ...record, actor: admin, action: 'user.deactivated', entity_type: 'user', entity_id: 42,
    details: { before: { is_active: true }, after: { is_active: false } } }], meta: { current_page: 1, last_page: 1, total: 1 } })
  page()
  expect(await screen.findByText('Llogaria u çaktivizua', { selector: 'td' })).toBeInTheDocument()
  expect(screen.getByRole('cell', { name: 'Admin Test' })).toBeInTheDocument()
  expect(screen.getByText('Përdorues #42')).toBeInTheDocument()
  expect(screen.getByText('Llogaria: Aktive → Joaktive')).toBeInTheDocument()
})

it('shows loading and cancels the pending request on unmount', async () => {
  listAuditLogs.mockImplementation(() => new Promise(() => {}))
  const memory = page()
  expect(await screen.findByText('Duke ngarkuar historikun…')).toBeInTheDocument()
  const signal = listAuditLogs.mock.calls[0][1]
  await memory.navigate('/profile')
  await waitFor(() => expect(signal.aborted).toBe(true))
})

it('shows empty results', async () => {
  listAuditLogs.mockResolvedValue({ data: [], meta: { current_page: 1, last_page: 1, total: 0 } })
  page()
  expect(await screen.findByText('Nuk u gjet asnjë veprim.')).toBeInTheDocument()
})

it('shows errors and supports retry without displaying stale rows', async () => {
  listAuditLogs.mockRejectedValueOnce({ status: 403 })
  page()
  expect(await screen.findByRole('alert')).toHaveTextContent('Nevojitet një llogari aktive administratori.')
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  expect(await screen.findByText('Arta Reader')).toBeInTheDocument()
})

it('handles deleted actors, unknown events and long values without raw JSON', async () => {
  listAuditLogs.mockResolvedValue({ data: [{ ...record, actor: null, action: 'unknown', entity_type: 'unknown', details: { before: {}, after: {} } },
    { ...record, id: 13, actor: { ...record.actor, email: `${'long'.repeat(40)}@example.test` }, details: { before: {}, after: { hours: '2.50', comment: 'PRIVATE', storage_path: 'PRIVATE' } } }], meta: { current_page: 1, last_page: 1, total: 2 } })
  page()
  expect(await screen.findByText('Sistemi / përdorues i fshirë')).toBeInTheDocument()
  expect(screen.getByText('Veprim i panjohur')).toBeInTheDocument()
  expect(screen.getByText('Orët: 2.50')).toBeInTheDocument()
  expect(screen.queryByText(/PRIVATE/)).not.toBeInTheDocument()
})

it.each([['STUDENT', '/student/internships'], ['COMPANY_SUPERVISOR', '/supervisor/internships'], ['ACADEMIC_COORDINATOR', '/coordinator/internships']])('denies the actual audit route to %s', async (role, landing) => {
  const memory = page({ ...admin, role })
  await waitFor(() => expect(memory.state.location.pathname).toBe(landing))
  expect(listAuditLogs).not.toHaveBeenCalled()
})

it('redirects guests to login without requesting audit history', async () => {
  const memory = page(null)
  await waitFor(() => expect(memory.state.location.pathname).toBe('/login'))
  expect(listAuditLogs).not.toHaveBeenCalled()
})

it('formats only allowlisted detail fields and preserves meaningful zero and false values', () => {
  expect(auditDetails({ before: { is_active: true, coordinator_id: null }, after: { is_active: false, coordinator_id: 7, version_no: 2, submitted: false, hours: 0, password: 'PRIVATE' } }))
    .toEqual(['Llogaria: Aktive → Joaktive', 'Koordinatori: Pa caktuar → #7', 'Orët: 0', 'Versioni: 2', 'Dorëzimi: Draft'])
})
