import { act, cleanup, configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getUnreadNotificationCount, listNotifications, markAllNotificationsAsRead, markNotificationAsRead } from './api/notificationsApi'

vi.mock('./api/notificationsApi', async original => ({ ...await original(), getUnreadNotificationCount: vi.fn(), listNotifications: vi.fn(), markNotificationAsRead: vi.fn(), markAllNotificationsAsRead: vi.fn() }))
vi.mock('../internships/api/internshipsApi', async original => ({ ...await original(), listInternships: vi.fn(async () => ({ data: [], meta: { total: 0, current_page: 1, last_page: 1 } })) }))
const stamp = '2026-10-10T10:00:00Z'
const make = (number, read = false, url = '/student/tasks/12') => ({ id: `00000000-0000-4000-8000-${String(number).padStart(12, '0')}`, payload: { title: `Njoftimi ${number}`, message: `Mesazhi ${number}`, action_url: url }, created_at: stamp, read_at: read ? stamp : null })
let store
const memories = []
function page(path = '/notifications', role = 'STUDENT', guest = false) {
  const memory = createMemoryRouter(router.routes, { initialEntries: [path] })
  memories.push(memory)
  render(<AuthContext.Provider value={{ user: guest ? null : { id: 8, first_name: 'Test', last_name: 'User', role, is_active: true }, isAuthenticated: !guest, isLoading: false, logout: vi.fn(), login: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>)
  return memory
}
beforeEach(() => {
  configure({ asyncUtilTimeout: 5000 })
  vi.resetAllMocks()
  store = [make(1), make(2, true, null)]
  getUnreadNotificationCount.mockImplementation(async () => store.filter(item => !item.read_at).length)
  listNotifications.mockImplementation(async ({ status, page, per_page }) => {
    const items = store.filter(item => status === 'all' || (status === 'read' ? Boolean(item.read_at) : !item.read_at))
    return { data: items.slice((page - 1) * per_page, page * per_page), meta: { total: items.length, current_page: page, last_page: Math.max(1, Math.ceil(items.length / per_page)) } }
  })
  markNotificationAsRead.mockImplementation(async id => {
    const item = store.find(item => item.id === id)
    item.read_at = stamp
    return { ...item }
  })
  markAllNotificationsAsRead.mockImplementation(async () => {
    const count = store.filter(item => !item.read_at).length
    store.forEach(item => { item.read_at ??= stamp })
    return count
  })
})
afterEach(() => { memories.splice(0).forEach(memory => memory.dispose()); configure({ asyncUtilTimeout: 1000 }) })

it.each(['STUDENT', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR', 'ADMIN'])('shares the bell and center with authenticated role %s', async role => {
  page('/notifications', role)
  expect(await screen.findByRole('button', { name: 'Njoftimet, 1 të palexuara' })).toBeInTheDocument()
  expect(await screen.findByRole('heading', { name: 'Njoftimet', level: 1 })).toBeInTheDocument()
  expect(await screen.findByText('Njoftimi 1')).toBeInTheDocument()
})

it('does not show or request notifications for a guest', async () => {
  page('/notifications', 'STUDENT', true)
  await screen.findByRole('heading', { name: 'Kyçu në llogarinë tënde' })
  expect(screen.queryByRole('button', { name: /Njoftimet,/ })).not.toBeInTheDocument()
  expect(getUnreadNotificationCount).not.toHaveBeenCalled()
  expect(listNotifications).not.toHaveBeenCalled()
})

it('caps badge at 99+ while the accessible label includes the actual count', async () => {
  getUnreadNotificationCount.mockResolvedValue(120)
  page()
  const button = await screen.findByRole('button', { name: 'Njoftimet, 120 të palexuara' })
  expect(within(button).getByText('99+')).toBeInTheDocument()
  expect(document.querySelector('.notification-sr-only')).toHaveAttribute('aria-live', 'polite')
})

it('opens recent dropdown with focus and closes with Escape restoring bell focus', async () => {
  page()
  const bell = await screen.findByRole('button', { name: 'Njoftimet, 1 të palexuara' })
  fireEvent.click(bell)
  const panel = await screen.findByRole('region', { name: 'Njoftimet e fundit' })
  expect(panel).toHaveFocus()
  expect(bell).toHaveAttribute('aria-expanded', 'true')
  expect(await within(panel).findByText('Njoftimi 1')).toBeInTheDocument()
  expect(listNotifications).toHaveBeenCalledWith({ status: 'all', page: 1, per_page: 5 }, expect.any(AbortSignal))
  fireEvent.keyDown(document, { key: 'Escape' })
  expect(screen.queryByRole('region', { name: 'Njoftimet e fundit' })).not.toBeInTheDocument()
  expect(bell).toHaveFocus()
})

it('closes on outside click and refreshes recent data when reopened', async () => {
  page()
  const bell = await screen.findByRole('button', { name: 'Njoftimet, 1 të palexuara' })
  fireEvent.click(bell)
  await within(screen.getByRole('region', { name: 'Njoftimet e fundit' })).findByText('Njoftimi 1')
  fireEvent.pointerDown(document.body)
  expect(screen.queryByRole('region', { name: 'Njoftimet e fundit' })).not.toBeInTheDocument()
  store = [make(3)]
  fireEvent.click(bell)
  await within(screen.getByRole('region', { name: 'Njoftimet e fundit' })).findByText('Njoftimi 3')
})

it('keeps center and dropdown synchronized after marking one read', async () => {
  page()
  const bell = await screen.findByRole('button', { name: 'Njoftimet, 1 të palexuara' })
  fireEvent.click(bell)
  const panel = screen.getByRole('region', { name: 'Njoftimet e fundit' })
  fireEvent.click(await within(panel).findByRole('button', { name: 'Shëno si të lexuar: Njoftimi 1' }))
  await screen.findByRole('button', { name: 'Njoftimet, 0 të palexuara' })
  await waitFor(() => expect(screen.queryByRole('button', { name: 'Shëno si të lexuar: Njoftimi 1' })).not.toBeInTheDocument())
  expect(markNotificationAsRead).toHaveBeenCalledExactlyOnceWith(store[0].id)
})

it('mark-all synchronizes lists and count while preserving the active filter', async () => {
  const memory = page('/notifications?status=unread')
  await screen.findByRole('button', { name: 'Njoftimet, 1 të palexuara' })
  fireEvent.click(screen.getByRole('button', { name: 'Lexoji të gjitha' }))
  await screen.findByRole('button', { name: 'Njoftimet, 0 të palexuara' })
  await screen.findByText('Nuk ka njoftime në këtë listë.')
  expect(memory.state.location.search).toContain('status=unread')
  expect(markAllNotificationsAsRead).toHaveBeenCalledTimes(1)
})

it('does not falsely mark read or navigate on failed mutation and allows retry', async () => {
  markNotificationAsRead.mockRejectedValueOnce({ status: 500 })
  const memory = page()
  fireEvent.click(await screen.findByRole('button', { name: 'Shëno si të lexuar: Njoftimi 1' }))
  await screen.findByRole('alert')
  expect(screen.getByRole('button', { name: 'Njoftimet, 1 të palexuara' })).toBeInTheDocument()
  expect(memory.state.location.pathname).toBe('/notifications')
  fireEvent.click(await screen.findByRole('button', { name: 'Shëno si të lexuar: Njoftimi 1' }))
  await screen.findByRole('button', { name: 'Njoftimet, 0 të palexuara' })
})

it('failed mark-all leaves count and unread items unchanged', async () => {
  markAllNotificationsAsRead.mockRejectedValue({ status: 500 })
  page()
  await screen.findByRole('button', { name: 'Njoftimet, 1 të palexuara' })
  fireEvent.click(screen.getByRole('button', { name: 'Lexoji të gjitha' }))
  await screen.findByRole('alert')
  expect(await screen.findByRole('button', { name: 'Shëno si të lexuar: Njoftimi 1' })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Njoftimet, 1 të palexuara' })).toBeInTheDocument()
})

it('rapid read clicks and overlapping mark-all do not duplicate mutation requests', async () => {
  let resolve
  markNotificationAsRead.mockImplementationOnce(() => new Promise(done => { resolve = done }))
  page()
  const button = await screen.findByRole('button', { name: 'Shëno si të lexuar: Njoftimi 1' })
  fireEvent.click(button); fireEvent.click(button); fireEvent.click(screen.getByRole('button', { name: 'Duke përditësuar…' }))
  expect(markNotificationAsRead).toHaveBeenCalledTimes(1)
  expect(markAllNotificationsAsRead).not.toHaveBeenCalled()
  await act(async () => { store[0].read_at = stamp; resolve(store[0]) })
  await screen.findByRole('button', { name: 'Njoftimet, 0 të palexuara' })
})

it('renders loading, empty and retry states', async () => {
  let reject
  listNotifications.mockImplementationOnce(() => new Promise((resolve, fail) => { reject = fail }))
  page()
  await screen.findByText('Duke ngarkuar njoftimet…')
  await act(async () => reject({ status: 500 }))
  await screen.findByRole('alert')
  store = []
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  await screen.findByText('Nuk ka njoftime në këtë listë.')
})

it('switches filters and paginates without duplicate entries or filter resets', async () => {
  store = Array.from({ length: 18 }, (_, index) => make(index + 1, index > 15, null))
  const memory = page()
  await screen.findByText('Njoftimi 1')
  fireEvent.click(screen.getByRole('button', { name: 'Të palexuara' }))
  await waitFor(() => expect(listNotifications).toHaveBeenLastCalledWith({ status: 'unread', page: 1, per_page: 15 }, expect.any(AbortSignal)))
  await screen.findByText('Njoftimi 1')
  fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
  await screen.findByText('Njoftimi 16')
  expect(screen.queryByText('Njoftimi 1')).not.toBeInTheDocument()
  expect(memory.state.location.search).toContain('status=unread')
  expect(memory.state.location.search).toContain('page=2')
  fireEvent.click(screen.getByRole('button', { name: 'Të lexuara' }))
  await screen.findByText('Njoftimi 17')
  expect(memory.state.location.search).not.toContain('page=2')
})

it('deduplicates repeated records from a response', async () => {
  listNotifications.mockResolvedValue({ data: [make(1), make(1)], meta: { total: 1, current_page: 1, last_page: 1 } })
  page()
  expect(await screen.findAllByText('Njoftimi 1')).toHaveLength(1)
})

it('opens a safe action only after successful read and closes dropdown on navigation', async () => {
  store = [make(1, false, '/student/internships')]
  const memory = page()
  fireEvent.click(await screen.findByRole('button', { name: 'Njoftimet, 1 të palexuara' }))
  const panel = screen.getByRole('region', { name: 'Njoftimet e fundit' })
  fireEvent.click(await within(panel).findByRole('button', { name: 'Njoftimi 1: Hap njoftimin' }))
  await waitFor(() => expect(memory.state.location.pathname).toBe('/student/internships'))
  expect(markNotificationAsRead).toHaveBeenCalledTimes(1)
  expect(screen.queryByRole('region', { name: 'Njoftimet e fundit' })).not.toBeInTheDocument()
})

it('renders unsafe or absent destinations as plain content, never as HTML', async () => {
  store = [{ ...make(1, false, 'javascript:alert(1)'), payload: { title: '<b>Plain title</b>', message: '<img src=x onerror=bad()>', action_url: 'javascript:alert(1)' } }]
  page()
  expect(await screen.findByText('<b>Plain title</b>')).toBeInTheDocument()
  expect(document.querySelector('.notification-content img')).toBe(null)
  expect(screen.queryByRole('button', { name: /Hap njoftimin/ })).not.toBeInTheDocument()
  expect(screen.getByText('<img src=x onerror=bad()>')).toBeInTheDocument()
})

it('ignores a stale list response after changing filters', async () => {
  let resolve
  listNotifications.mockImplementationOnce(() => new Promise(done => { resolve = done }))
  page()
  await waitFor(() => expect(resolve).toBeTypeOf('function'))
  fireEvent.click(screen.getByRole('button', { name: 'Të lexuara' }))
  await screen.findByText('Njoftimi 2')
  await act(async () => resolve({ data: [make(99)], meta: { total: 1, current_page: 1, last_page: 1 } }))
  expect(screen.queryByText('Njoftimi 99')).not.toBeInTheDocument()
})

it('ignores stale count response after a newer read mutation', async () => {
  let resolve
  getUnreadNotificationCount.mockImplementationOnce(() => new Promise(done => { resolve = done }))
  page()
  fireEvent.click(await screen.findByRole('button', { name: 'Shëno si të lexuar: Njoftimi 1' }))
  await screen.findByRole('button', { name: 'Njoftimet, 0 të palexuara' })
  await act(async () => resolve(99))
  expect(screen.getByRole('button', { name: 'Njoftimet, 0 të palexuara' })).toBeInTheDocument()
})

it('refreshes unread count on visible window focus', async () => {
  page()
  await screen.findByRole('button', { name: 'Njoftimet, 1 të palexuara' })
  store.push(make(3))
  fireEvent(window, new Event('focus'))
  await screen.findByRole('button', { name: 'Njoftimet, 2 të palexuara' })
})

it('supports a count refresh failure and retry without claiming zero unread', async () => {
  getUnreadNotificationCount.mockRejectedValueOnce({ status: 500 })
  page()
  await screen.findByRole('alert')
  expect(screen.getByRole('button', { name: 'Njoftimet, numri i padisponueshëm' })).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Përditëso numrin' }))
  await screen.findByRole('button', { name: 'Njoftimet, 1 të palexuara' })
})

it.each(['STUDENT', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR', 'ADMIN'])('also shows shared bell on the authenticated session page for %s', async role => {
  page('/session', role)
  await screen.findByRole('heading', { name: 'Test User' })
  expect(await screen.findByRole('button', { name: 'Njoftimet, 1 të palexuara' })).toBeInTheDocument()
})

it('never navigates when opening an unread notification fails to mark it read', async () => {
  markNotificationAsRead.mockRejectedValue({ status: 404 })
  const memory = page()
  fireEvent.click(await screen.findByRole('button', { name: 'Njoftimi 1: Hap njoftimin' }))
  await screen.findByText('Njoftimi nuk është më i disponueshëm.')
  expect(memory.state.location.pathname).toBe('/notifications')
  expect(await screen.findByRole('button', { name: 'Shëno si të lexuar: Njoftimi 1' })).toBeInTheDocument()
})

it('view-all navigates to the center and closes the dropdown', async () => {
  const memory = page('/session')
  fireEvent.click(await screen.findByRole('button', { name: 'Njoftimet, 1 të palexuara' }))
  fireEvent.click(await screen.findByRole('link', { name: 'Shiko të gjitha njoftimet →' }))
  await screen.findByRole('heading', { name: 'Njoftimet', level: 1 })
  expect(memory.state.location.pathname).toBe('/notifications')
  expect(screen.queryByRole('region', { name: 'Njoftimet e fundit' })).not.toBeInTheDocument()
})

it('clamps an emptied unread page after a successful read without resetting its filter', async () => {
  store = Array.from({ length: 16 }, (_, index) => make(index + 1, false, null))
  const memory = page('/notifications?status=unread&page=2')
  fireEvent.click(await screen.findByRole('button', { name: 'Shëno si të lexuar: Njoftimi 16' }))
  await screen.findByText('Njoftimi 1')
  expect(memory.state.location.search).toContain('status=unread')
  expect(memory.state.location.search).not.toContain('page=2')
})

it('clamps invalid negative counts rather than showing a negative badge', async () => {
  getUnreadNotificationCount.mockResolvedValue(-5)
  page()
  const bell = await screen.findByRole('button', { name: 'Njoftimet, 0 të palexuara' })
  expect(bell.querySelector('.notification-badge')).toBe(null)
})

it('cleans up request cancellation and focus listeners when the authenticated subtree unmounts', async () => {
  page()
  await screen.findByText('Njoftimi 1')
  const signal = listNotifications.mock.calls[0][1]
  cleanup()
  expect(signal.aborted).toBe(true)
  const calls = getUnreadNotificationCount.mock.calls.length
  fireEvent(window, new Event('focus'))
  expect(getUnreadNotificationCount).toHaveBeenCalledTimes(calls)
})

it('does not reopen the dropdown when browser history returns to its previous location', async () => {
  const memory = page()
  fireEvent.click(await screen.findByRole('button', { name: 'Njoftimet, 1 të palexuara' }))
  await screen.findByRole('region', { name: 'Njoftimet e fundit' })
  fireEvent.click(screen.getByRole('button', { name: 'Të lexuara' }))
  await waitFor(() => expect(memory.state.location.search).toContain('status=read'))
  expect(screen.queryByRole('region', { name: 'Njoftimet e fundit' })).not.toBeInTheDocument()
  await act(async () => memory.navigate(-1))
  expect(screen.queryByRole('region', { name: 'Njoftimet e fundit' })).not.toBeInTheDocument()
})
