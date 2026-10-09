import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getStudentTask, getSupervisorTask, listTaskSubmissions, resubmitTask, reviewTask } from './api/tasksApi'

vi.mock('./api/tasksApi', async (original) => ({ ...await original(), getStudentTask: vi.fn(), getSupervisorTask: vi.fn(), listTaskSubmissions: vi.fn(), resubmitTask: vi.fn(), reviewTask: vi.fn() }))
const student = { id: 8, first_name: 'Ada', last_name: 'Student', role: 'STUDENT', is_active: true }
const supervisor = { ...student, id: 9, role: 'COMPANY_SUPERVISOR' }
const task = { id: 3, title: 'Build form', description: 'Test inputs', priority: 'MEDIUM', status: 'REVISION_REQUIRED', can_edit: false, can_resubmit: true, can_review: false, has_submissions: true, created_at: '2026-10-08T10:00:00Z', updated_at: '2026-10-08T10:00:00Z', internship: { id: 7, position_title: 'Web internship', status: 'ACTIVE', student: { first_name: 'Ada', last_name: 'Student' }, company: { name: 'Acme' } } }
const v1 = { id: 11, version_no: 1, submission_text: 'Original work', resource_url: 'https://example.com/v1', submitted_at: '2026-10-08T12:00:00Z', feedback: { decision: 'REVISION_REQUIRED', comment: 'Add tests and explain edge cases.', created_at: '2026-10-08T13:00:00Z' } }
const v2 = { ...v1, id: 12, version_no: 2, submission_text: 'Corrected work', resource_url: 'https://example.com/v2', feedback: null }
const meta = { total: 1, current_page: 1, last_page: 1 }
const routers = []
const refreshUser = vi.fn()
function renderPage(path = '/student/tasks/3', user = student) {
  const memory = createMemoryRouter(router.routes, { initialEntries: [path] }); routers.push(memory)
  render(<AuthContext.Provider value={{ user, isAuthenticated: true, isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>); return memory
}
async function prepare() {
  fireEvent.click(await screen.findByRole('button', { name: 'Rishiko dhe ridorëzo' }))
  fireEvent.change(screen.getByLabelText('Puna e përfunduar *'), { target: { value: v2.submission_text } })
  fireEvent.change(screen.getByLabelText('Lidhja e punës (opsionale)'), { target: { value: v2.resource_url } })
  fireEvent.click(screen.getByRole('button', { name: 'Ridorëzo punën', exact: true }))
}
function submitted() {
  getStudentTask.mockResolvedValue({ ...task, status: 'SUBMITTED', can_resubmit: false })
  listTaskSubmissions.mockResolvedValue({ data: [v2, v1], meta: { ...meta, total: 2 } })
}
beforeEach(() => {
  vi.resetAllMocks()
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
  getStudentTask.mockResolvedValue(task)
  getSupervisorTask.mockResolvedValue({ ...task, status: 'SUBMITTED', can_review: true, can_resubmit: false })
  listTaskSubmissions.mockResolvedValue({ data: [v1], meta })
})
afterEach(() => routers.splice(0).forEach((memory) => memory.dispose()))

it('shows instructions and explicitly opens a prefilled correction form without submitting', async () => {
  renderPage(); await screen.findByText(v1.feedback.comment)
  expect(screen.getByText('Versioni më i fundit')).toBeInTheDocument()
  expect(screen.queryByLabelText('Puna e përfunduar *')).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Rishiko dhe ridorëzo' }))
  expect(screen.getByLabelText('Puna e përfunduar *')).toHaveValue(v1.submission_text)
  expect(screen.getByLabelText('Lidhja e punës (opsionale)')).toHaveValue(v1.resource_url)
  fireEvent.change(screen.getByLabelText('Puna e përfunduar *'), { target: { value: 'Draft correction' } })
  expect(resubmitTask).not.toHaveBeenCalled()
  fireEvent.click(screen.getByRole('button', { name: 'Anulo korrigjimin' }))
  fireEvent.click(screen.getByRole('button', { name: 'Rishiko dhe ridorëzo' }))
  expect(screen.getByLabelText('Puna e përfunduar *')).toHaveValue(v1.submission_text)
})

it('requires confirmation and cancels while retaining unsent correction text', async () => {
  renderPage(); await prepare()
  const dialog = screen.getByRole('dialog', { name: 'Konfirmoni ridorëzimin?' })
  expect(dialog).toHaveAccessibleDescription(/Versionet dhe komentet e mëparshme ruhen/)
  fireEvent.click(within(dialog).getByRole('button', { name: 'Anulo' }))
  expect(resubmitTask).not.toHaveBeenCalled()
  expect(screen.getByLabelText('Puna e përfunduar *')).toHaveValue(v2.submission_text)
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('validates blank descriptions and insecure URLs without posting', async () => {
  renderPage(); fireEvent.click(await screen.findByRole('button', { name: 'Rishiko dhe ridorëzo' }))
  fireEvent.change(screen.getByLabelText('Puna e përfunduar *'), { target: { value: ' \n ' } })
  fireEvent.click(screen.getByRole('button', { name: 'Ridorëzo punën' }))
  expect(screen.getByText('Përshkruani punën e përfunduar.')).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Puna e përfunduar *'), { target: { value: 'Correction' } })
  fireEvent.change(screen.getByLabelText('Lidhja e punës (opsionale)'), { target: { value: 'http://example.com' } })
  fireEvent.click(screen.getByRole('button', { name: 'Ridorëzo punën' }))
  expect(screen.getByText(/Vendosni një lidhje HTTPS/)).toBeInTheDocument()
  expect(resubmitTask).not.toHaveBeenCalled()
})

it('resubmits once with stale-form token and displays a new version without deleting prior feedback', async () => {
  let finish
  resubmitTask.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  renderPage(); await prepare()
  const confirm = screen.getByRole('button', { name: 'Konfirmo ridorëzimin' })
  fireEvent.click(confirm); fireEvent.click(confirm)
  expect(screen.getByRole('button', { name: 'Duke dorëzuar…' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'Anulo' })).toBeDisabled()
  expect(screen.getByText('Kërkohet korrigjim', { selector: '.internship-status' })).toBeInTheDocument()
  submitted(); finish({ ...task, status: 'SUBMITTED' })
  await screen.findByText('E dorëzuar', { selector: '.internship-status' })
  await screen.findByRole('heading', { name: 'Dorëzimi 2' })
  expect(resubmitTask).toHaveBeenCalledExactlyOnceWith(3, { submission_text: v2.submission_text, resource_url: v2.resource_url, expected_submission_id: 11 })
  expect(screen.getByRole('heading', { name: 'Dorëzimi 1' })).toBeInTheDocument()
  expect(screen.getByText(v1.feedback.comment)).toBeInTheDocument()
  expect(screen.getByText('Version i mëparshëm')).toBeInTheDocument()
  expect(screen.getByText('Dorëzim në pritje të shqyrtimit.')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Rishiko dhe ridorëzo' })).not.toBeInTheDocument()
})

it('retains correction input after server validation and authentication errors', async () => {
  resubmitTask.mockRejectedValueOnce({ status: 422, validationErrors: { submission_text: ['Server field error'] } }).mockRejectedValueOnce({ status: 401 })
  renderPage(); await prepare(); fireEvent.click(screen.getByRole('button', { name: 'Konfirmo ridorëzimin' }))
  await screen.findByText('Server field error')
  expect(screen.getByLabelText('Puna e përfunduar *')).toHaveValue(v2.submission_text)
  fireEvent.click(screen.getByRole('button', { name: 'Ridorëzo punën' })); fireEvent.click(screen.getByRole('button', { name: 'Konfirmo ridorëzimin' }))
  await screen.findByText(/Sesioni ka përfunduar/)
  await waitFor(() => expect(refreshUser).toHaveBeenCalledTimes(1))
  expect(screen.getByLabelText('Puna e përfunduar *')).toHaveValue(v2.submission_text)
})

it('refreshes a 409 conflict and clears stale form/dialog', async () => {
  resubmitTask.mockRejectedValue({ status: 409 }); renderPage(); await prepare(); submitted()
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo ridorëzimin' }))
  await screen.findByRole('heading', { name: 'Dorëzimi 2' })
  expect(screen.getByText(/Gjendja ka ndryshuar/)).toBeInTheDocument()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.queryByLabelText('Puna e përfunduar *')).not.toBeInTheDocument()
})

it('never offers resubmission for approved tasks or without revision feedback', async () => {
  getStudentTask.mockResolvedValue({ ...task, status: 'APPROVED', can_resubmit: true })
  const memory = renderPage(); await screen.findByRole('heading', { name: 'Dorëzimi 1' })
  expect(screen.queryByRole('button', { name: 'Rishiko dhe ridorëzo' })).not.toBeInTheDocument()
  getStudentTask.mockResolvedValue({ ...task, id: 4 })
  listTaskSubmissions.mockResolvedValue({ data: [{ ...v1, feedback: null }], meta })
  await memory.navigate('/student/tasks/4'); await screen.findByRole('heading', { name: 'Dorëzimi 1' })
  expect(screen.queryByRole('button', { name: 'Rishiko dhe ridorëzo' })).not.toBeInTheDocument()
})

it('supervisor reviews only the newest version while old feedback stays visible', async () => {
  listTaskSubmissions.mockResolvedValue({ data: [v2, v1], meta: { ...meta, total: 2 } }); reviewTask.mockResolvedValue(task)
  renderPage('/supervisor/tasks/3', supervisor)
  fireEvent.click(await screen.findByRole('button', { name: 'Mirato punën' }))
  expect(screen.getByRole('dialog')).toHaveTextContent('Dorëzimi 2')
  getSupervisorTask.mockResolvedValue({ ...task, status: 'APPROVED', can_review: false })
  listTaskSubmissions.mockResolvedValue({ data: [{ ...v2, feedback: { ...v1.feedback, decision: 'APPROVED', comment: 'Now correct' } }, v1], meta: { ...meta, total: 2 } })
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo miratimin' })); await screen.findByText('Now correct')
  expect(reviewTask).toHaveBeenCalledWith(3, { decision: 'APPROVED', comment: null, expected_submission_id: 12 })
  expect(screen.getByText(v1.feedback.comment)).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Mirato punën' })).not.toBeInTheDocument()
})

it('clears correction draft and old history on task ID navigation', async () => {
  const memory = renderPage(); await prepare()
  getStudentTask.mockResolvedValue({ ...task, id: 4, title: 'Second task', status: 'APPROVED', can_resubmit: false })
  listTaskSubmissions.mockResolvedValue({ data: [{ ...v1, submission_text: 'Second task work', feedback: { ...v1.feedback, decision: 'APPROVED', comment: 'Second review' } }], meta })
  await memory.navigate('/student/tasks/4'); await screen.findByText('Second task work')
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.queryByLabelText('Puna e përfunduar *')).not.toBeInTheDocument()
  expect(screen.queryByText(v1.feedback.comment)).not.toBeInTheDocument()
  expect(screen.queryByText(v2.submission_text)).not.toBeInTheDocument()
})

it('keeps older pages historical and offers no correction or review controls there', async () => {
  listTaskSubmissions.mockResolvedValueOnce({ data: [v2], meta: { total: 16, current_page: 1, last_page: 2 } }).mockResolvedValueOnce({ data: [v1], meta: { total: 16, current_page: 2, last_page: 2 } })
  renderPage(); await screen.findByRole('heading', { name: 'Dorëzimi 2' })
  fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
  await screen.findByRole('heading', { name: 'Dorëzimi 1' })
  expect(listTaskSubmissions).toHaveBeenLastCalledWith('student', 3, 2, expect.any(AbortSignal))
  expect(screen.getByText('Version i mëparshëm')).toBeInTheDocument()
  expect(screen.queryByText('Versioni më i fundit')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Rishiko dhe ridorëzo' })).not.toBeInTheDocument()
})
