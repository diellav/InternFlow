import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getStudentTask, getSupervisorTask, listTaskSubmissions, reviewTask } from './api/tasksApi'

vi.mock('./api/tasksApi', async (original) => ({ ...await original(), getStudentTask: vi.fn(), getSupervisorTask: vi.fn(), listTaskSubmissions: vi.fn(), reviewTask: vi.fn() }))
const supervisor = { id: 9, first_name: 'Drita', last_name: 'Mentor', role: 'COMPANY_SUPERVISOR', is_active: true }
const student = { ...supervisor, id: 8, role: 'STUDENT' }
const task = { id: 3, title: 'Build form', description: 'Test inputs', priority: 'MEDIUM', status: 'SUBMITTED', can_edit: false, can_review: true, has_submissions: true, created_at: '2026-10-08T10:00:00Z', updated_at: '2026-10-08T10:00:00Z', internship: { id: 7, position_title: 'Web internship', status: 'ACTIVE', student: { first_name: 'Ada', last_name: 'Student' }, company: { name: 'Acme' } } }
const submission = { id: 11, version_no: 1, submission_text: 'Completed work', submitted_at: '2026-10-08T12:00:00Z', feedback: null }
const feedback = { decision: 'APPROVED', comment: 'Good work', created_at: '2026-10-08T13:00:00Z' }
const meta = { total: 1, current_page: 1, last_page: 1 }
const refreshUser = vi.fn()
const routers = []
function renderPage(path = '/supervisor/tasks/3', user = supervisor) {
  const memory = createMemoryRouter(router.routes, { initialEntries: [path] }); routers.push(memory)
  render(<AuthContext.Provider value={{ user, isAuthenticated: true, isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>); return memory
}
function reviewed(decision = 'APPROVED', comment = 'Good work') {
  getSupervisorTask.mockResolvedValue({ ...task, status: decision, can_review: false })
  listTaskSubmissions.mockResolvedValue({ data: [{ ...submission, feedback: { ...feedback, decision, comment } }], meta })
}
beforeEach(() => {
  vi.resetAllMocks()
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
  getSupervisorTask.mockResolvedValue(task)
  getStudentTask.mockResolvedValue({ ...task, can_review: false })
  listTaskSubmissions.mockResolvedValue({ data: [submission], meta })
})
afterEach(() => routers.splice(0).forEach((memory) => memory.dispose()))

it('does not retain task-save notices or old conflicts after a later review', async () => {
  reviewTask.mockResolvedValue(task)
  renderPage({ pathname: '/supervisor/tasks/3', state: { notice: 'Old task-save success', conflict: 'Old editing conflict' } })
  fireEvent.click(await screen.findByRole('button', { name: 'Mirato punën' }))
  expect(screen.queryByText('Old task-save success')).not.toBeInTheDocument()
  expect(screen.getByText('Old editing conflict')).toBeInTheDocument()
  reviewed()
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo miratimin' }))
  await screen.findByText('Good work', { selector: 'p' })
  expect(screen.queryByText('Old task-save success')).not.toBeInTheDocument()
  expect(screen.queryByText('Old editing conflict')).not.toBeInTheDocument()
  expect(screen.getByText('Gjendja e detyrës u përditësua.')).toBeInTheDocument()
})

it('requires submitted eligibility and latest loaded unreviewed work for actions', async () => {
  getSupervisorTask.mockResolvedValue({ ...task, status: 'IN_PROGRESS', can_review: false })
  renderPage(); await screen.findByText('Completed work')
  expect(screen.queryByRole('button', { name: 'Mirato punën' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Kërko korrigjime' })).not.toBeInTheDocument()
})

it('confirms approval with accessible labels and can cancel without sending', async () => {
  renderPage(); fireEvent.click(await screen.findByRole('button', { name: 'Mirato punën' }))
  const dialog = screen.getByRole('dialog', { name: 'Miratoni punën?' })
  expect(dialog).toHaveAccessibleDescription(/jo përfundimin e praktikës/)
  expect(within(dialog).getByLabelText('Komenti (opsional)')).toBeInTheDocument()
  fireEvent.click(within(dialog).getByRole('button', { name: 'Anulo' }))
  expect(reviewTask).not.toHaveBeenCalled(); expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('sends one approval with expected submission and reloads stored feedback', async () => {
  let finish
  reviewTask.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  renderPage(); fireEvent.click(await screen.findByRole('button', { name: 'Mirato punën' }))
  fireEvent.change(screen.getByLabelText('Komenti (opsional)'), { target: { value: '  Good work  ' } })
  const confirm = screen.getByRole('button', { name: 'Konfirmo miratimin' })
  fireEvent.click(confirm); fireEvent.submit(screen.getByRole('dialog').querySelector('form'))
  expect(screen.getByRole('button', { name: 'Duke ruajtur vendimin…' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'Anulo' })).toBeDisabled()
  expect(screen.getByText('E dorëzuar', { selector: '.internship-status' })).toBeInTheDocument()
  reviewed(); finish({ ...task, status: 'APPROVED' })
  await screen.findByText('E miratuar', { selector: '.internship-status' })
  await screen.findByText('Good work', { selector: 'p' })
  expect(reviewTask).toHaveBeenCalledExactlyOnceWith(3, { decision: 'APPROVED', comment: 'Good work', expected_submission_id: 11 })
  expect(screen.getByText('E miratuar', { selector: '.internship-status' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Mirato punën' })).not.toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'Ndrysho detyrën' })).not.toBeInTheDocument()
})

it('allows optional blank approval comment', async () => {
  reviewTask.mockResolvedValue(task); renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Mirato punën' })); reviewed('APPROVED', null)
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo miratimin' }))
  await screen.findByText('Puna është miratuar')
  expect(reviewTask).toHaveBeenCalledWith(3, { decision: 'APPROVED', comment: null, expected_submission_id: 11 })
})

it('requires trimmed correction instructions and shows persisted revision feedback', async () => {
  reviewTask.mockResolvedValue(task); renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Kërko korrigjime' }))
  fireEvent.change(screen.getByLabelText('Udhëzimet e korrigjimit *'), { target: { value: '  ' } })
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo korrigjimet' }))
  expect(screen.getByText('Shkruani udhëzimet e korrigjimit.')).toBeInTheDocument(); expect(reviewTask).not.toHaveBeenCalled()
  fireEvent.change(screen.getByLabelText('Udhëzimet e korrigjimit *'), { target: { value: '  Add tests.\nExplain edge cases.  ' } })
  reviewed('REVISION_REQUIRED', 'Add tests.\nExplain edge cases.')
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo korrigjimet' }))
  await screen.findByText('Kërkohet korrigjim', { selector: '.internship-status' })
  await screen.findByText(/Add tests\. Explain edge cases\./, { selector: 'p' })
  expect(reviewTask).toHaveBeenCalledWith(3, { decision: 'REVISION_REQUIRED', comment: 'Add tests.\nExplain edge cases.', expected_submission_id: 11 })
  expect(screen.getByText('Kërkohet korrigjim', { selector: '.internship-status' })).toBeInTheDocument()
})

it('preserves dialog input after 422 and restores enabled controls after network failure', async () => {
  reviewTask.mockRejectedValueOnce({ status: 422, validationErrors: { comment: ['Server comment error'] } }).mockRejectedValueOnce({ status: 500 })
  renderPage(); fireEvent.click(await screen.findByRole('button', { name: 'Kërko korrigjime' }))
  fireEvent.change(screen.getByLabelText('Udhëzimet e korrigjimit *'), { target: { value: 'Fix tests' } })
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo korrigjimet' })); await screen.findByText('Server comment error')
  expect(screen.getByLabelText('Udhëzimet e korrigjimit *')).toHaveValue('Fix tests')
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo korrigjimet' })); await screen.findByText(/Veprimi nuk u krye/)
  expect(screen.getByRole('button', { name: 'Konfirmo korrigjimet' })).toBeEnabled()
})

it('recovers stale review by fetching task and submission with final feedback', async () => {
  reviewTask.mockRejectedValue({ status: 409 }); renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Mirato punën' })); reviewed('REVISION_REQUIRED', 'Already reviewed')
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo miratimin' }))
  await screen.findByText('Already reviewed')
  expect(screen.getByText(/Gjendja ka ndryshuar/)).toBeInTheDocument()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Mirato punën' })).not.toBeInTheDocument()
  expect(listTaskSubmissions).toHaveBeenCalledTimes(2)
})

it('shows approval and revision to students read-only without resubmission controls', async () => {
  getStudentTask.mockResolvedValue({ ...task, status: 'REVISION_REQUIRED', can_review: false })
  reviewed('REVISION_REQUIRED', 'Add tests')
  const memory = renderPage('/student/tasks/3', student); await screen.findByText('Add tests')
  expect(screen.getByRole('heading', { name: 'Kërkohen korrigjime' })).toBeInTheDocument()
  for (const name of ['Mirato punën', 'Kërko korrigjime', 'Dorëzo punën', 'Fillo punën']) expect(screen.queryByRole('button', { name })).not.toBeInTheDocument()
  getStudentTask.mockResolvedValue({ ...task, id: 4, status: 'APPROVED', can_review: false }); reviewed()
  await memory.navigate('/student/tasks/4'); await screen.findByText('Puna është miratuar')
  expect(screen.queryByText('Add tests')).not.toBeInTheDocument()
  expect(screen.queryByText(/Modulin 5D/)).not.toBeInTheDocument()
})

it('closes dialog and clears old feedback when navigating to a different task ID', async () => {
  const memory = renderPage(); fireEvent.click(await screen.findByRole('button', { name: 'Kërko korrigjime' }))
  fireEvent.change(screen.getByLabelText('Udhëzimet e korrigjimit *'), { target: { value: 'Old draft' } })
  getSupervisorTask.mockResolvedValue({ ...task, id: 4, title: 'Second task', status: 'APPROVED', can_review: false })
  listTaskSubmissions.mockResolvedValue({ data: [{ ...submission, id: 12, feedback }], meta })
  await memory.navigate('/supervisor/tasks/4'); await screen.findByText('Good work')
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument(); expect(screen.queryByText('Old draft')).not.toBeInTheDocument()
})

it('refreshes authentication on forbidden review without losing comment', async () => {
  reviewTask.mockRejectedValue({ status: 403 }); renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Kërko korrigjime' }))
  fireEvent.change(screen.getByLabelText('Udhëzimet e korrigjimit *'), { target: { value: 'Fix tests' } })
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo korrigjimet' })); await screen.findByRole('alert')
  await waitFor(() => expect(refreshUser).toHaveBeenCalledTimes(1))
  expect(screen.getByLabelText('Udhëzimet e korrigjimit *')).toHaveValue('Fix tests')
})
