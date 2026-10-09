import { configure, fireEvent, render, screen, waitFor } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { activeActivityInternships, getActivity, getActivityInternship, getSupervisorActivity, listActivities, listSupervisorActivities, saveActivity } from './api/activitiesApi'
import { ActivityFilters } from './components/ActivityFilters'

vi.mock('./api/activitiesApi', async (original) => ({ ...await original(), activeActivityInternships: vi.fn(), getActivity: vi.fn(), getActivityInternship: vi.fn(), getSupervisorActivity: vi.fn(), listActivities: vi.fn(), listSupervisorActivities: vi.fn(), saveActivity: vi.fn() }))
const internship = { id: 7, position_title: 'Web internship', status: 'ACTIVE', start_date: '2026-10-01', end_date: '2026-10-31', server_date: '2026-10-09' }
const activity = { id: 3, title: 'Original diary', description: 'Work performed', activity_date: '2026-10-02', hours: '2.50', created_at: '2026-10-02T12:00:00Z', internship, can_edit: true }
const result = { data: [activity], meta: { total: 17, current_page: 1, last_page: 2 }, internship, total_recorded_hours: '7.50', filtered_recorded_hours: '7.50' }
const routers = []
const refreshUser = vi.fn()
const api = (role) => role === 'student' ? listActivities : listSupervisorActivities
const path = (role, query = '') => role === 'student' ? `/student/activities?internship=7${query ? `&${query}` : ''}` : `/supervisor/internships/7/activities${query ? `?${query}` : ''}`
function renderPage(role, query = '') {
  const memory = createMemoryRouter(router.routes, { initialEntries: [path(role, query)] }); routers.push(memory)
  const user = { id: 9, first_name: 'Test', last_name: 'User', role: role === 'student' ? 'STUDENT' : 'COMPANY_SUPERVISOR', is_active: true }
  render(<AuthContext.Provider value={{ user, isAuthenticated: true, isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>); return memory
}
function apply() { fireEvent.click(screen.getByRole('button', { name: 'Apliko filtrat' })) }
beforeEach(() => {
  configure({ asyncUtilTimeout: 5000 })
  vi.resetAllMocks()
  activeActivityInternships.mockResolvedValue([internship, { ...internship, id: 8, position_title: 'Second internship' }])
  getActivity.mockResolvedValue(activity)
  getActivityInternship.mockResolvedValue(internship)
  getSupervisorActivity.mockResolvedValue({ ...activity, can_edit: false })
  for (const request of [listActivities, listSupervisorActivities]) request.mockImplementation(async (id, params) => ({ ...result, internship: { ...internship, id: Number(id) }, meta: { ...result.meta, current_page: params.page } }))
})
afterEach(() => { routers.splice(0).forEach((memory) => memory.dispose()); configure({ asyncUtilTimeout: 1000 }) })

it.each(['student', 'supervisor'])('%s applies explicit date and trimmed search filters and displays both server totals', async (role) => {
  const memory = renderPage(role)
  await screen.findByRole('link', { name: 'Original diary' })
  fireEvent.change(screen.getByLabelText('Data nga'), { target: { value: '2026-10-02' } })
  fireEvent.change(screen.getByLabelText('Data deri'), { target: { value: '2026-10-03' } })
  fireEvent.change(screen.getByLabelText('Kërko në titull ose përshkrim'), { target: { value: '  diary%_  ' } })
  expect(api(role)).toHaveBeenCalledTimes(1)
  api(role).mockResolvedValue({ ...result, filtered_recorded_hours: '2.50', meta: { total: 1, current_page: 1, last_page: 1 } })
  apply()
  await screen.findByText('2.50 orë', { selector: '.activity-hours-filtered' })
  expect(screen.getByText('7.50 orë', { selector: '.activity-hours-total' })).toBeInTheDocument()
  expect(api(role)).toHaveBeenLastCalledWith('7', { page: 1, per_page: 15, date_from: '2026-10-02', date_to: '2026-10-03', search: 'diary%_' }, expect.any(AbortSignal))
  expect(new URLSearchParams(memory.state.location.search).get('search')).toBe('diary%_')
})

it.each(['student', 'supervisor'])('%s retains filters on pagination and resets page on apply and clear', async (role) => {
  const memory = renderPage(role, 'search=old&page=2&date_to=2026-10-09')
  await screen.findByRole('link', { name: 'Original diary' })
  fireEvent.change(screen.getByLabelText('Kërko në titull ose përshkrim'), { target: { value: 'new' } })
  apply()
  await waitFor(() => expect(api(role)).toHaveBeenLastCalledWith('7', { page: 1, per_page: 15, date_to: '2026-10-09', search: 'new' }, expect.any(AbortSignal)))
  await screen.findByRole('link', { name: 'Original diary' })
  fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
  await waitFor(() => expect(api(role)).toHaveBeenLastCalledWith('7', { page: 2, per_page: 15, date_to: '2026-10-09', search: 'new' }, expect.any(AbortSignal)))
  fireEvent.click(screen.getByRole('button', { name: 'Pastro filtrat' }))
  await waitFor(() => expect(api(role)).toHaveBeenLastCalledWith('7', { page: 1, per_page: 15 }, expect.any(AbortSignal)))
  expect(screen.getByLabelText('Data deri')).toHaveValue('')
  expect(screen.getByLabelText('Kërko në titull ose përshkrim')).toHaveValue('')
  expect(new URLSearchParams(memory.state.location.search).has('page')).toBe(false)
})

it.each(['student', 'supervisor'])('%s validates reversed dates and excessive search length without changing selected dates or making requests', async (role) => {
  renderPage(role)
  await screen.findByRole('link', { name: 'Original diary' })
  fireEvent.change(screen.getByLabelText('Data nga'), { target: { value: '2026-10-03' } })
  fireEvent.change(screen.getByLabelText('Data deri'), { target: { value: '2026-10-02' } })
  fireEvent.change(screen.getByLabelText('Kërko në titull ose përshkrim'), { target: { value: 'x'.repeat(256) } })
  apply()
  expect(screen.getByText('Data e fundit duhet të jetë e njëjtë ose pas datës së fillimit.')).toBeInTheDocument()
  expect(screen.getByText('Kërkimi lejon maksimumi 255 karaktere.')).toBeInTheDocument()
  expect(screen.getByLabelText('Data nga')).toHaveValue('2026-10-03')
  expect(screen.getByLabelText('Data deri')).toHaveValue('2026-10-02')
  expect(api(role)).toHaveBeenCalledTimes(1)
})

it.each(['student', 'supervisor'])('%s distinguishes no matching activities from an empty diary and retains the all-internship total', async (role) => {
  api(role).mockResolvedValue({ ...result, data: [], meta: { total: 0, current_page: 1, last_page: 1 }, filtered_recorded_hours: '0.00' })
  renderPage(role, 'search=absent')
  await screen.findByRole('heading', { name: 'Nuk ka aktivitete që përputhen' })
  expect(screen.getByText('7.50 orë', { selector: '.activity-hours-total' })).toBeInTheDocument()
  expect(screen.getByText('0.00 orë', { selector: '.activity-hours-filtered' })).toBeInTheDocument()
  expect(screen.getByText('0 aktivitete · Faqja 1 nga 1')).toBeInTheDocument()
})

it.each(['student', 'supervisor'])('%s clears stale records and summaries while loading changed filters and retries the same criteria after 422', async (role) => {
  let reject
  renderPage(role)
  await screen.findByRole('link', { name: 'Original diary' })
  api(role).mockImplementationOnce(() => new Promise((_, failure) => { reject = failure }))
  fireEvent.change(screen.getByLabelText('Kërko në titull ose përshkrim'), { target: { value: 'new' } }); apply()
  expect(screen.queryByRole('link', { name: 'Original diary' })).not.toBeInTheDocument()
  expect(screen.queryByText('7.50 orë', { selector: '.activity-hours-total' })).not.toBeInTheDocument()
  await screen.findByText('Duke ngarkuar aktivitetet…')
  reject({ status: 422 })
  await screen.findByText('Kontrolloni fushat dhe provoni përsëri.')
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  await screen.findByRole('link', { name: 'Original diary' })
  expect(api(role)).toHaveBeenLastCalledWith('7', { page: 1, per_page: 15, search: 'new' }, expect.any(AbortSignal))
})

it.each(['student', 'supervisor'])('%s ignores aborted late filter results, including their old totals', async (role) => {
  let finish
  renderPage(role)
  await screen.findByRole('link', { name: 'Original diary' })
  api(role).mockImplementationOnce(() => new Promise((resolve) => { finish = resolve })).mockResolvedValue({ ...result, data: [{ ...activity, title: 'Fast diary' }], filtered_recorded_hours: '1.25' })
  fireEvent.change(screen.getByLabelText('Kërko në titull ose përshkrim'), { target: { value: 'slow' } }); apply()
  await waitFor(() => expect(api(role)).toHaveBeenCalledTimes(2))
  const signal = api(role).mock.calls[1][2]
  fireEvent.change(screen.getByLabelText('Kërko në titull ose përshkrim'), { target: { value: 'fast' } }); apply()
  await screen.findByRole('link', { name: 'Fast diary' })
  expect(signal.aborted).toBe(true)
  finish(result)
  await waitFor(() => expect(screen.queryByRole('link', { name: 'Original diary' })).not.toBeInTheDocument())
  expect(screen.getByText('1.25 orë', { selector: '.activity-hours-filtered' })).toBeInTheDocument()
})

it.each(['student', 'supervisor'])('%s restores applied URL filters and resets records when changing internship', async (role) => {
  const memory = renderPage(role, 'date_from=2026-10-01&search=diary&page=2')
  await screen.findByRole('link', { name: 'Original diary' })
  expect(screen.getByLabelText('Data nga')).toHaveValue('2026-10-01')
  expect(screen.getByLabelText('Kërko në titull ose përshkrim')).toHaveValue('diary')
  expect(api(role)).toHaveBeenLastCalledWith('7', { page: 2, per_page: 15, date_from: '2026-10-01', search: 'diary' }, expect.any(AbortSignal))
  api(role).mockResolvedValue({ ...result, data: [{ ...activity, title: 'Second internship diary' }], internship: { ...internship, id: 8 }, filtered_recorded_hours: '0.10', total_recorded_hours: '0.10' })
  if (role === 'student') fireEvent.change(screen.getByLabelText('Praktika aktive'), { target: { value: '8' } })
  else await memory.navigate('/supervisor/internships/8/activities')
  await screen.findByRole('link', { name: 'Second internship diary' })
  expect(screen.getByLabelText('Data nga')).toHaveValue('')
  expect(api(role)).toHaveBeenLastCalledWith('8', { page: 1, per_page: 15 }, expect.any(AbortSignal))
  expect(screen.queryByRole('link', { name: 'Original diary' })).not.toBeInTheDocument()
  if (role === 'supervisor') expect(screen.queryByRole('link', { name: /Regjistro|Ndrysho|Fshi/ })).not.toBeInTheDocument()
})

it('validates impossible initial calendar dates and accepts either bound independently', () => {
  const onApply = vi.fn()
  render(<ActivityFilters filters={{ date_from: '2026-02-30', date_to: '', search: '' }} onApply={onApply} onClear={vi.fn()} />)
  apply()
  expect(screen.getByText('Vendosni një datë të vlefshme.')).toBeInTheDocument()
  expect(onApply).not.toHaveBeenCalled()
  fireEvent.change(screen.getByLabelText('Data nga'), { target: { value: '' } })
  fireEvent.change(screen.getByLabelText('Data deri'), { target: { value: '2026-10-02' } })
  apply()
  expect(onApply).toHaveBeenCalledWith({ date_from: '', date_to: '2026-10-02', search: '' })
})

it.each(['student', 'supervisor'])('%s returns from details to the same applied filters and page', async (role) => {
  const memory = renderPage(role, 'search=diary&page=2&date_from=2026-10-01')
  fireEvent.click(await screen.findByRole('link', { name: 'Original diary' }))
  await screen.findByRole('heading', { name: 'Original diary' })
  fireEvent.click(screen.getByRole('link', { name: '← Aktivitetet e praktikës' }))
  await screen.findByRole('link', { name: 'Original diary' })
  expect(new URLSearchParams(memory.state.location.search).get('page')).toBe('2')
  expect(screen.getByLabelText('Data nga')).toHaveValue('2026-10-01')
  expect(screen.getByLabelText('Kërko në titull ose përshkrim')).toHaveValue('diary')
})

it('reloads the same filtered list and updated summaries after a student edits an activity', async () => {
  renderPage('student', 'search=diary&date_from=2026-10-01')
  fireEvent.click(await screen.findByRole('link', { name: 'Original diary' }))
  fireEvent.click(await screen.findByRole('link', { name: 'Ndrysho aktivitetin' }))
  fireEvent.change(await screen.findByLabelText('Orët e punës (opsionale)'), { target: { value: '3.25' } })
  const updated = { ...activity, hours: '3.25' }
  saveActivity.mockResolvedValue(updated)
  getActivity.mockResolvedValue(updated)
  listActivities.mockResolvedValue({ ...result, data: [updated], total_recorded_hours: '8.25', filtered_recorded_hours: '3.25' })
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj ndryshimet' }))
  await screen.findByText('Aktiviteti u përditësua me sukses.')
  fireEvent.click(screen.getByRole('link', { name: '← Aktivitetet e praktikës' }))
  await screen.findByText('8.25 orë', { selector: '.activity-hours-total' })
  expect(screen.getByText('3.25 orë', { selector: '.activity-hours-filtered' })).toBeInTheDocument()
  expect(listActivities).toHaveBeenLastCalledWith('7', { page: 1, per_page: 15, date_from: '2026-10-01', search: 'diary' }, expect.any(AbortSignal))
})

it.each(['create', 'edit'])('preserves filters and pagination when returning from the %s form heading', async (mode) => {
  const memory = renderPage('student', 'search=diary&page=2&date_from=2026-10-01')
  await screen.findByRole('link', { name: 'Original diary' })
  if (mode === 'create') fireEvent.click(screen.getByRole('link', { name: 'Regjistro aktivitet' }))
  else {
    fireEvent.click(screen.getByRole('link', { name: 'Original diary' }))
    fireEvent.click(await screen.findByRole('link', { name: 'Ndrysho aktivitetin' }))
  }
  await screen.findByLabelText('Titulli *')
  fireEvent.click(screen.getByRole('link', { name: '← Aktivitetet e praktikës' }))
  await screen.findByRole('link', { name: 'Original diary' })
  expect(new URLSearchParams(memory.state.location.search).get('page')).toBe('2')
  expect(screen.getByLabelText('Data nga')).toHaveValue('2026-10-01')
  expect(screen.getByLabelText('Kërko në titull ose përshkrim')).toHaveValue('diary')
  expect(listActivities).toHaveBeenLastCalledWith('7', { page: 2, per_page: 15, date_from: '2026-10-01', search: 'diary' }, expect.any(AbortSignal))
})
