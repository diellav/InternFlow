import { fireEvent, render, screen, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { activeActivityInternships, getActivity, getActivityInternship, listActivities, saveActivity } from './api/activitiesApi'

vi.mock('./api/activitiesApi', async (original) => ({ ...await original(), activeActivityInternships: vi.fn(), getActivity: vi.fn(), getActivityInternship: vi.fn(), listActivities: vi.fn(), saveActivity: vi.fn() }))
const student = { id: 8, first_name: 'Arta', last_name: 'Student', role: 'STUDENT', is_active: true }
const internship = { id: 7, position_title: 'Web internship', status: 'ACTIVE', start_date: '2026-10-01', end_date: '2026-10-31', server_date: '2026-10-09' }
const activity = { id: 3, title: 'Work diary', description: 'Implemented and tested the form.', activity_date: '2026-10-09', hours: '2.50', created_at: '2026-10-09T12:00:00Z', can_edit: true, internship }
const meta = { total: 1, current_page: 1, last_page: 1 }
const routers = []
const refreshUser = vi.fn()
function renderPage(path = '/student/activities?internship=7', user = student) {
  const memory = createMemoryRouter(router.routes, { initialEntries: [path] }); routers.push(memory)
  render(<AuthContext.Provider value={{ user, isAuthenticated: Boolean(user), isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>); return memory
}
async function fill() {
  fireEvent.change(await screen.findByLabelText('Titulli *'), { target: { value: activity.title } })
  fireEvent.change(screen.getByLabelText('Puna e kryer *'), { target: { value: activity.description } })
  fireEvent.change(screen.getByLabelText('Orët e punës (opsionale)'), { target: { value: '2.50' } })
}
beforeEach(() => {
  vi.resetAllMocks()
  activeActivityInternships.mockResolvedValue([internship, { ...internship, id: 8, position_title: 'Second internship' }])
  getActivityInternship.mockResolvedValue(internship)
  getActivity.mockResolvedValue(activity)
  listActivities.mockResolvedValue({ data: [activity], meta, internship })
})
afterEach(() => routers.splice(0).forEach((memory) => memory.dispose()))

it('adds student navigation and lists activity date, description and working hours', async () => {
  renderPage()
  expect(await screen.findByRole('link', { name: 'Work diary' })).toHaveAttribute('href', '/student/activities/3')
  expect(screen.getByRole('link', { name: 'Aktivitetet e mia', exact: true })).toHaveAttribute('href', '/student/activities')
  expect(screen.getByText('2026-10-09 · 2.50 orë')).toBeInTheDocument()
  expect(screen.getByText(activity.description)).toBeInTheDocument()
})

it('switches between multiple active internships and discards late results from the previous selection', async () => {
  let finish
  listActivities.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve })).mockResolvedValue({ data: [{ ...activity, title: 'Second work' }], meta, internship: { ...internship, id: 8 } })
  const memory = renderPage()
  await screen.findByLabelText('Praktika aktive')
  fireEvent.change(screen.getByLabelText('Praktika aktive'), { target: { value: '8' } })
  await screen.findByRole('link', { name: 'Second work' })
  finish({ data: [activity], meta, internship })
  await waitFor(() => expect(screen.queryByRole('link', { name: 'Work diary' })).not.toBeInTheDocument())
  expect(memory.state.location.search).toBe('?internship=8')
  expect(screen.getByRole('link', { name: 'Regjistro aktivitet', exact: true })).toHaveAttribute('href', '/student/internships/8/activities/new')
})

it('selects a default internship and handles empty diaries', async () => {
  listActivities.mockResolvedValue({ data: [], meta: { ...meta, total: 0 }, internship })
  const memory = renderPage('/student/activities')
  await screen.findByRole('heading', { name: 'Nuk ka aktivitete' })
  expect(memory.state.location.search).toBe('?internship=7')
})

it('handles students with no active internships', async () => {
  activeActivityInternships.mockResolvedValue([])
  renderPage('/student/activities')
  await screen.findByRole('heading', { name: 'Nuk keni praktikë aktive' })
  expect(screen.queryByRole('link', { name: 'Regjistro aktivitet', exact: true })).not.toBeInTheDocument()
})

it('validates required fields, internship dates and positive two-decimal hours', async () => {
  renderPage('/student/internships/7/activities/new')
  fireEvent.click(await screen.findByRole('button', { name: 'Regjistro aktivitetin', exact: true }))
  expect(screen.getByLabelText('Titulli *')).toHaveFocus()
  await fill()
  fireEvent.change(screen.getByLabelText('Data e aktivitetit *'), { target: { value: '2026-10-10' } })
  fireEvent.change(screen.getByLabelText('Orët e punës (opsionale)'), { target: { value: '24.01' } })
  fireEvent.click(screen.getByRole('button', { name: 'Regjistro aktivitetin' }))
  expect(screen.getByText('Zgjidhni një datë brenda praktikës, jo në të ardhmen.')).toBeInTheDocument()
  expect(screen.getByText('Vendosni 0.01–24 orë, me deri në dy shifra dhjetore.')).toBeInTheDocument()
  expect(saveActivity).not.toHaveBeenCalled()
  expect(screen.getByLabelText('Data e aktivitetit *')).toHaveAttribute('min', '2026-10-01')
  expect(screen.getByLabelText('Data e aktivitetit *')).toHaveAttribute('max', '2026-10-09')
})

it('creates once, disables pending controls, shows stored details and refreshes the list on return', async () => {
  let finish
  saveActivity.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  renderPage('/student/internships/7/activities/new'); await fill()
  const button = screen.getByRole('button', { name: 'Regjistro aktivitetin' })
  fireEvent.click(button); fireEvent.submit(screen.getByLabelText('Titulli *').closest('form'))
  expect(screen.getByRole('button', { name: 'Duke ruajtur…' })).toBeDisabled()
  expect(saveActivity).toHaveBeenCalledExactlyOnceWith(7, undefined, { title: activity.title, description: activity.description, activity_date: '2026-10-09', hours: '2.50' })
  finish(activity)
  await screen.findByText('Aktiviteti u regjistrua me sukses.')
  expect(screen.getByRole('heading', { name: 'Work diary' })).toBeInTheDocument()
  fireEvent.click(screen.getByRole('link', { name: '← Aktivitetet e praktikës' }))
  await screen.findByRole('link', { name: 'Work diary' })
  expect(listActivities).toHaveBeenCalledWith('7', { page: 1, per_page: 15 }, expect.any(AbortSignal))
})

it('prefills editing, saves the same activity, and reloads stored updated values', async () => {
  const updated = { ...activity, title: 'Updated diary', hours: null }
  saveActivity.mockResolvedValue(updated)
  renderPage('/student/activities/3/edit')
  expect(await screen.findByLabelText('Titulli *')).toHaveValue(activity.title)
  fireEvent.change(screen.getByLabelText('Titulli *'), { target: { value: updated.title } })
  fireEvent.change(screen.getByLabelText('Orët e punës (opsionale)'), { target: { value: '' } })
  getActivity.mockResolvedValue(updated)
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj ndryshimet' }))
  await screen.findByRole('heading', { name: updated.title })
  expect(screen.getByText('Aktiviteti u përditësua me sukses.')).toBeInTheDocument()
  expect(saveActivity).toHaveBeenCalledWith(7, 3, expect.objectContaining({ hours: null }))
  expect(screen.getByText('Nuk janë shënuar')).toBeInTheDocument()
})

it('preserves form values and field errors after server validation and authentication failures', async () => {
  saveActivity.mockRejectedValueOnce({ status: 422, validationErrors: { hours: ['Daily total exceeds 24 hours'] } }).mockRejectedValueOnce({ status: 401 })
  renderPage('/student/internships/7/activities/new'); await fill()
  fireEvent.click(screen.getByRole('button', { name: 'Regjistro aktivitetin' }))
  await screen.findByText('Daily total exceeds 24 hours')
  expect(screen.getByLabelText('Titulli *')).toHaveValue(activity.title)
  fireEvent.click(screen.getByRole('button', { name: 'Regjistro aktivitetin' }))
  await screen.findByText('Sesioni ka përfunduar. Kyçuni përsëri.')
  await waitFor(() => expect(refreshUser).toHaveBeenCalledTimes(1))
})

it('recovers 409 by leaving a stale edit and reloading active internship options', async () => {
  saveActivity.mockRejectedValue({ status: 409 })
  renderPage('/student/activities/3/edit'); await screen.findByLabelText('Titulli *')
  activeActivityInternships.mockResolvedValue([{ ...internship, id: 8 }])
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj ndryshimet' }))
  await screen.findByText('Gjendja e praktikës ka ndryshuar. Aktivitetet ndryshohen vetëm në praktikë aktive.')
  expect(screen.queryByLabelText('Titulli *')).not.toBeInTheDocument()
  expect(screen.getByLabelText('Praktika aktive')).toBeInTheDocument()
})

it('handles loading, pagination, failed reads and retry without stale data', async () => {
  listActivities.mockRejectedValueOnce({ status: 500 }).mockResolvedValue({ data: [activity], meta: { ...meta, total: 16, last_page: 2 }, internship })
  renderPage()
  await screen.findByRole('alert')
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  await screen.findByRole('link', { name: 'Work diary' })
  fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
  await waitFor(() => expect(listActivities).toHaveBeenLastCalledWith('7', { page: 2, per_page: 15 }, expect.any(AbortSignal)))
})

it('clears details and unsent forms while navigating between activity IDs', async () => {
  const memory = renderPage('/student/activities/3/edit')
  fireEvent.change(await screen.findByLabelText('Titulli *'), { target: { value: 'Unsent edit' } })
  getActivity.mockResolvedValue({ ...activity, id: 4, title: 'Second activity' })
  await memory.navigate('/student/activities/4/edit')
  await waitFor(() => expect(screen.getByLabelText('Titulli *')).toHaveValue('Second activity'))
  await memory.navigate('/student/activities/4')
  await screen.findByRole('heading', { name: 'Second activity' })
  expect(screen.queryByText('Unsent edit')).not.toBeInTheDocument()
  getActivity.mockRejectedValue({ status: 404 })
  await memory.navigate('/student/activities/99')
  await screen.findByText('Aktiviteti ose praktika nuk u gjet ose nuk është aktive për ju.')
  expect(screen.queryByRole('heading', { name: 'Second activity' })).not.toBeInTheDocument()
})

it('protects activity routes from guests and non-student roles', async () => {
  const memory = renderPage('/student/activities', null)
  await waitFor(() => expect(memory.state.location.pathname).toBe('/login'))
  expect(activeActivityInternships).not.toHaveBeenCalled()
})
