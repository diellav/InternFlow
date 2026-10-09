import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getSupervisorInternship, listTasks } from '../tasks/api/tasksApi'
import { getSupervisorActivity, listSupervisorActivities } from './api/activitiesApi'

vi.mock('./api/activitiesApi', async (original) => ({ ...await original(), getSupervisorActivity: vi.fn(), listSupervisorActivities: vi.fn() }))
vi.mock('../tasks/api/tasksApi', async (original) => ({ ...await original(), getSupervisorInternship: vi.fn(), listTasks: vi.fn() }))
const supervisor = { id: 9, first_name: 'Drita', last_name: 'Mentor', role: 'COMPANY_SUPERVISOR', is_active: true }
const internship = { id: 7, position_title: 'Web internship', status: 'ACTIVE', start_date: '2026-10-01', end_date: '2026-10-31', student: { first_name: 'Arta', last_name: 'Student' } }
const activity = { id: 3, title: 'Student diary', description: 'Work performed', activity_date: '2026-10-09', hours: '2.50', created_at: '2026-10-09T12:00:00Z', can_edit: false, internship }
const meta = { total: 1, current_page: 1, last_page: 1 }
const result = { data: [activity], meta, internship, total_recorded_hours: '2.50' }
const refreshUser = vi.fn()
const routers = []
function renderPage(path = '/supervisor/internships/7/activities', user = supervisor) {
  const memory = createMemoryRouter(router.routes, { initialEntries: [path] }); routers.push(memory)
  render(<AuthContext.Provider value={{ user, isAuthenticated: Boolean(user), isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>); return memory
}
beforeEach(() => {
  vi.resetAllMocks()
  listSupervisorActivities.mockResolvedValue(result)
  getSupervisorActivity.mockResolvedValue(activity)
  getSupervisorInternship.mockResolvedValue(internship)
  listTasks.mockResolvedValue({ data: [], meta: { ...meta, total: 0 } })
})
afterEach(() => routers.splice(0).forEach((memory) => memory.dispose()))

it('links to student activities from an active supervisor internship and preserves profile navigation', async () => {
  renderPage('/supervisor/internships/7')
  expect(await screen.findByRole('link', { name: 'Shiko aktivitetet e studentit' })).toHaveAttribute('href', '/supervisor/internships/7/activities')
  expect(screen.getByRole('link', { name: 'Profili', exact: true })).toHaveAttribute('href', '/profile')
  fireEvent.click(screen.getByRole('link', { name: 'Shiko aktivitetet e studentit' }))
  await screen.findByText('2.50 orë', { selector: '.activity-hours-total' })
  expect(screen.getByRole('link', { name: 'Student diary' })).toHaveAttribute('href', '/supervisor/activities/3')
  expect(screen.getByText('2026-10-09 · 2.50 orë')).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: /Regjistro|Ndrysho|Krijo/ })).not.toBeInTheDocument()
})

it('does not offer activity navigation for a nonactive internship', async () => {
  getSupervisorInternship.mockResolvedValue({ ...internship, status: 'APPROVED' })
  renderPage('/supervisor/internships/7')
  await screen.findByRole('heading', { name: 'Web internship' })
  expect(screen.queryByRole('link', { name: 'Shiko aktivitetet e studentit' })).not.toBeInTheDocument()
})

it('shows read-only details, unrecorded hours and a supervisor-specific back link', async () => {
  getSupervisorActivity.mockResolvedValue({ ...activity, hours: null, can_edit: true })
  renderPage('/supervisor/activities/3')
  await screen.findByRole('heading', { name: 'Student diary' })
  expect(screen.getByText('Nuk janë shënuar')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: '← Aktivitetet e praktikës' })).toHaveAttribute('href', '/supervisor/internships/7/activities')
  expect(screen.queryByRole('link', { name: 'Ndrysho aktivitetin' })).not.toBeInTheDocument()
  expect(screen.getByText(activity.description)).toBeInTheDocument()
})

it('shows empty diaries and zero recorded hours without inventing a working entry', async () => {
  listSupervisorActivities.mockResolvedValue({ ...result, data: [], meta: { ...meta, total: 0 }, total_recorded_hours: '0.00' })
  renderPage()
  await screen.findByRole('heading', { name: 'Nuk ka aktivitete' })
  expect(screen.getByText('0.00 orë')).toBeInTheDocument()
  expect(screen.getByText('Studenti nuk ka regjistruar aktivitete për këtë praktikë.')).toBeInTheDocument()
})

it('paginates without calculating the total from the visible page and displays nullable hours', async () => {
  listSupervisorActivities.mockResolvedValueOnce({ ...result, meta: { ...meta, total: 16, last_page: 2 }, total_recorded_hours: '32.50' })
    .mockResolvedValue({ ...result, data: [{ ...activity, hours: null }], meta: { total: 16, current_page: 2, last_page: 2 }, total_recorded_hours: '32.50' })
  renderPage()
  await screen.findByText('32.50 orë')
  fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
  await screen.findByText('2026-10-09 · Orët nuk janë shënuar')
  expect(screen.getByText('32.50 orë')).toBeInTheDocument()
  expect(listSupervisorActivities).toHaveBeenLastCalledWith('7', { page: 2, per_page: 15 }, expect.any(AbortSignal))
})

it('shows loading, recovers a failed read and refreshes auth on eligibility failure', async () => {
  let finish
  listSupervisorActivities.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve })).mockRejectedValueOnce({ status: 403 })
  const memory = renderPage()
  await screen.findByText('Duke ngarkuar aktivitetet…')
  finish(result)
  await screen.findByRole('link', { name: 'Student diary' })
  await memory.navigate('/supervisor/internships/8/activities')
  await screen.findByText('Nevojitet një llogari aktive mbikëqyrësi me profil dhe kompani të miratuar.')
  await waitFor(() => expect(refreshUser).toHaveBeenCalledTimes(1))
  listSupervisorActivities.mockResolvedValue({ ...result, internship: { ...internship, id: 8 } })
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  await screen.findByRole('link', { name: 'Student diary' })
})

it('clears previous internship records and hours, aborts late results and resets pagination across IDs', async () => {
  let finish
  listSupervisorActivities.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  const memory = renderPage()
  await waitFor(() => expect(listSupervisorActivities).toHaveBeenCalled())
  listSupervisorActivities.mockResolvedValue({ ...result, data: [{ ...activity, title: 'Second diary' }], internship: { ...internship, id: 8, position_title: 'Second internship' }, total_recorded_hours: '4.00' })
  await memory.navigate('/supervisor/internships/8/activities')
  await screen.findByRole('link', { name: 'Second diary' })
  finish(result)
  await waitFor(() => expect(screen.queryByRole('link', { name: 'Student diary' })).not.toBeInTheDocument())
  expect(screen.queryByText('2.50 orë', { selector: '.activity-hours-total' })).not.toBeInTheDocument()
  expect(screen.getByText('4.00 orë')).toBeInTheDocument()
  expect(listSupervisorActivities).toHaveBeenLastCalledWith('8', { page: 1, per_page: 15 }, expect.any(AbortSignal))
})

it('clears old details when a foreign or missing activity ID is opened', async () => {
  const memory = renderPage('/supervisor/activities/3')
  await screen.findByRole('heading', { name: 'Student diary' })
  getSupervisorActivity.mockRejectedValue({ status: 404 })
  await memory.navigate('/supervisor/activities/99')
  await screen.findByText('Aktiviteti ose praktika aktive nuk u gjet në praktikat tuaja të caktuara.')
  expect(screen.queryByText('Work performed')).not.toBeInTheDocument()
  expect(screen.queryByRole('heading', { name: 'Student diary' })).not.toBeInTheDocument()
})
