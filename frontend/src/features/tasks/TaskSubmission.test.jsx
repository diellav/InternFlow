import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getStudentTask, getSupervisorTask, listTaskSubmissions, startTask, submitTask } from './api/tasksApi'

vi.mock('./api/tasksApi', async (original) => ({ ...await original(), getStudentTask: vi.fn(), getSupervisorTask: vi.fn(), listTaskSubmissions: vi.fn(), startTask: vi.fn(), submitTask: vi.fn() }))
const student = { id: 8, first_name: 'Ada', last_name: 'Student', role: 'STUDENT', is_active: true }
const supervisor = { ...student, id: 9, role: 'COMPANY_SUPERVISOR' }
const assigned = { id: 3, title: 'Build form', description: 'Test inputs', priority: 'MEDIUM', status: 'ASSIGNED', due_date: '2026-10-20', can_edit: false, can_start: true, can_submit: false, has_submissions: false, created_at: '2026-10-08T10:00:00Z', updated_at: '2026-10-08T10:00:00Z', internship: { id: 7, position_title: 'Web internship', status: 'ACTIVE', student: { first_name: 'Ada', last_name: 'Student' }, company: { name: 'Acme' } } }
const progress = { ...assigned, status: 'IN_PROGRESS', can_start: false, can_submit: true }
const submitted = { ...progress, status: 'SUBMITTED', can_submit: false, has_submissions: true }
const record = { id: 1, version_no: 1, submission_text: 'Completed validation.', resource_url: 'https://github.com/example/work', submitted_at: '2026-10-08T12:00:00Z' }
const meta = { total: 1, current_page: 1, last_page: 1 }
const routers = []
const refreshUser = vi.fn()
function renderPage(path = '/student/tasks/3', user = student) {
  const memory = createMemoryRouter(router.routes, { initialEntries: [path] })
  routers.push(memory)
  render(<AuthContext.Provider value={{ user, isAuthenticated: true, isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>)
  return memory
}
async function prepare() {
  fireEvent.change(await screen.findByLabelText('Puna e përfunduar *'), { target: { value: record.submission_text } })
  fireEvent.change(screen.getByLabelText('Lidhja e punës (opsionale)'), { target: { value: record.resource_url } })
  fireEvent.click(screen.getByRole('button', { name: 'Dorëzo punën', exact: true }))
}
beforeEach(() => {
  vi.resetAllMocks()
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
  getStudentTask.mockResolvedValue(assigned)
  getSupervisorTask.mockResolvedValue({ ...submitted, can_start: false })
  listTaskSubmissions.mockResolvedValue({ data: [record], meta })
  refreshUser.mockResolvedValue(student)
})
afterEach(() => routers.splice(0).forEach((memory) => memory.dispose()))

it('does not automatically start and starts once with fresh server status', async () => {
  let finish
  startTask.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  renderPage()
  const button = await screen.findByRole('button', { name: 'Fillo punën' })
  expect(startTask).not.toHaveBeenCalled()
  fireEvent.click(button); fireEvent.click(button)
  expect(screen.getByRole('button', { name: 'Duke filluar…' })).toBeDisabled()
  expect(screen.getByText('E caktuar', { selector: '.internship-status' })).toBeInTheDocument()
  getStudentTask.mockResolvedValue(progress); finish(progress)
  await screen.findByLabelText('Puna e përfunduar *')
  expect(startTask).toHaveBeenCalledExactlyOnceWith(3)
  expect(getStudentTask).toHaveBeenCalledTimes(2)
})

it('validates required description and HTTPS link before confirmation', async () => {
  getStudentTask.mockResolvedValue(progress); renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Dorëzo punën' }))
  expect(screen.getByText('Përshkruani punën e përfunduar.')).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Puna e përfunduar *'), { target: { value: 'Work' } })
  fireEvent.change(screen.getByLabelText('Lidhja e punës (opsionale)'), { target: { value: 'javascript:alert(1)' } })
  fireEvent.click(screen.getByRole('button', { name: 'Dorëzo punën' }))
  expect(screen.getByText(/Vendosni një lidhje HTTPS/)).toBeInTheDocument()
  expect(submitTask).not.toHaveBeenCalled(); expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('confirms and cancels without sending work', async () => {
  getStudentTask.mockResolvedValue(progress); renderPage(); await prepare()
  const dialog = screen.getByRole('dialog', { name: 'Konfirmoni dorëzimin?' })
  expect(dialog).toHaveAccessibleDescription(/nuk mund të ndryshohet/)
  fireEvent.click(within(dialog).getByRole('button', { name: 'Anulo' }))
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.getByLabelText('Puna e përfunduar *')).toHaveValue(record.submission_text)
  expect(submitTask).not.toHaveBeenCalled()
})

it('submits only supported fields once and reloads read-only submitted content', async () => {
  let finish
  submitTask.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  getStudentTask.mockResolvedValue(progress); renderPage(); await prepare()
  const confirm = screen.getByRole('button', { name: 'Konfirmo dorëzimin' })
  fireEvent.click(confirm); fireEvent.click(confirm)
  expect(screen.getByRole('button', { name: 'Duke dorëzuar…' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'Anulo' })).toBeDisabled()
  getStudentTask.mockResolvedValue(submitted); finish(submitted)
  await screen.findByText('E dorëzuar', { selector: '.internship-status' })
  await screen.findByText(record.submission_text, { selector: 'p' })
  expect(submitTask).toHaveBeenCalledExactlyOnceWith(3, { submission_text: record.submission_text, resource_url: record.resource_url })
  expect(screen.getByText('E dorëzuar', { selector: '.internship-status' })).toBeInTheDocument()
  expect(screen.getByText(/Në pritje të shqyrtimit/)).toBeInTheDocument()
  expect(screen.queryByLabelText('Puna e përfunduar *')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Fillo punën' })).not.toBeInTheDocument()
})

it('keeps work and field errors after API validation failure', async () => {
  submitTask.mockRejectedValue({ status: 422, validationErrors: { submission_text: ['Server validation'], resource_url: ['Invalid link'] } })
  getStudentTask.mockResolvedValue(progress); renderPage(); await prepare()
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo dorëzimin' }))
  await screen.findByText('Server validation')
  expect(screen.getByLabelText('Puna e përfunduar *')).toHaveValue(record.submission_text)
  expect(screen.getByLabelText('Lidhja e punës (opsionale)')).toHaveValue(record.resource_url)
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('recovers from stale submission by refreshing server status', async () => {
  submitTask.mockRejectedValue({ status: 409 })
  getStudentTask.mockResolvedValue(progress); renderPage(); await prepare()
  getStudentTask.mockResolvedValue(submitted)
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo dorëzimin' }))
  await screen.findByText('E dorëzuar', { selector: '.internship-status' })
  await screen.findByText(record.submission_text, { selector: 'p' })
  expect(screen.getByText(/Gjendja ka ndryshuar/)).toBeInTheDocument()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.queryByLabelText('Puna e përfunduar *')).not.toBeInTheDocument()
})

it('recovers stale starts and refreshes authentication on denied actions', async () => {
  startTask.mockRejectedValueOnce({ status: 403 }).mockRejectedValueOnce({ status: 409 })
  renderPage(); fireEvent.click(await screen.findByRole('button', { name: 'Fillo punën' }))
  await screen.findByRole('alert'); expect(refreshUser).toHaveBeenCalledTimes(1)
  getStudentTask.mockResolvedValue(progress)
  fireEvent.click(screen.getByRole('button', { name: 'Fillo punën' }))
  await screen.findByLabelText('Puna e përfunduar *')
  expect(screen.getByText(/Gjendja ka ndryshuar/)).toBeInTheDocument()
})

it('shows supervisor submitted work without editing or review controls', async () => {
  renderPage('/supervisor/tasks/3', supervisor)
  await screen.findByText(record.submission_text)
  expect(listTaskSubmissions).toHaveBeenCalledWith('supervisor', 3, 1, expect.any(AbortSignal))
  expect(screen.getByRole('link', { name: record.resource_url })).toHaveAttribute('rel', 'noopener noreferrer')
  expect(screen.queryByRole('link', { name: 'Ndrysho detyrën' })).not.toBeInTheDocument()
  for (const name of ['Mirato', 'Refuzo', 'Kërko korrigjime', 'Fillo punën', 'Dorëzo punën']) expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
})

it('handles loading, failure, retry and empty submission reads', async () => {
  let fail
  listTaskSubmissions.mockImplementationOnce(() => new Promise((resolve, reject) => { fail = reject }))
  getStudentTask.mockResolvedValue(submitted); renderPage()
  await screen.findByText('Duke ngarkuar dorëzimet…'); fail({ status: 500 })
  await screen.findByRole('alert')
  listTaskSubmissions.mockResolvedValue({ data: [], meta: { ...meta, total: 0 } })
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  await screen.findByText('Nuk ka dorëzime.')
})

it('clears form and dialog when navigating to another task', async () => {
  getStudentTask.mockResolvedValue(progress)
  const memory = renderPage(); await prepare()
  getStudentTask.mockResolvedValue({ ...assigned, id: 4, title: 'Second task' })
  await memory.navigate('/student/tasks/4')
  await screen.findByRole('heading', { name: 'Second task' })
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.queryByText(record.submission_text)).not.toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Fillo punën' })).toBeInTheDocument()
})

it('discards late submission data after task ID navigation and rejects unsafe legacy links', async () => {
  let finish
  listTaskSubmissions.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve })).mockResolvedValue({ data: [{ ...record, submission_text: 'Second work', resource_url: 'javascript:alert(1)' }], meta })
  getStudentTask.mockResolvedValue(submitted)
  const memory = renderPage(); await screen.findByText('Duke ngarkuar dorëzimet…')
  getStudentTask.mockResolvedValue({ ...submitted, id: 4, title: 'Second task' })
  await memory.navigate('/student/tasks/4')
  await screen.findByText('Second work'); finish({ data: [record], meta })
  await waitFor(() => expect(screen.queryByText(record.submission_text)).not.toBeInTheDocument())
  expect(screen.queryByRole('link', { name: record.resource_url })).not.toBeInTheDocument()
})
