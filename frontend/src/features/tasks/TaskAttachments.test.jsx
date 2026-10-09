import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { downloadTaskFile, getStudentTask, getSupervisorTask, listTaskSubmissions, resubmitTask, submitTask } from './api/tasksApi'

vi.mock('./api/tasksApi', async (original) => ({ ...await original(), getStudentTask: vi.fn(), getSupervisorTask: vi.fn(), listTaskSubmissions: vi.fn(), submitTask: vi.fn(), resubmitTask: vi.fn(), downloadTaskFile: vi.fn() }))
const student = { id: 8, first_name: 'Ada', last_name: 'Student', role: 'STUDENT', is_active: true }
const task = { id: 3, title: 'Build form', description: 'Test inputs', priority: 'MEDIUM', status: 'IN_PROGRESS', can_submit: true, has_submissions: false, created_at: '2026-10-08T10:00:00Z', updated_at: '2026-10-08T10:00:00Z', internship: { id: 7, position_title: 'Web internship', status: 'ACTIVE', student: { first_name: 'Ada', last_name: 'Student' }, company: { name: 'Acme' } } }
const file = { id: 21, original_name: 'Report_v1.pdf', mime_type: 'application/pdf', size_bytes: 1024 * 1024 * 2, download_path: '/api/task-submission-files/21/download' }
const url = `https://example.com/${'long-path-'.repeat(20)}`
const submission = { id: 11, version_no: 1, submission_text: 'Original work', resource_url: url, submitted_at: '2026-10-08T12:00:00Z', files: [file], feedback: null }
const meta = { total: 1, current_page: 1, last_page: 1 }
const routers = []
const refreshUser = vi.fn()
function renderPage(user = student) {
  const memory = createMemoryRouter(router.routes, { initialEntries: [`/${user.role === 'STUDENT' ? 'student' : 'supervisor'}/tasks/3`] }); routers.push(memory)
  render(<AuthContext.Provider value={{ user, isAuthenticated: true, isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>)
}
async function select(files) { fireEvent.change(await screen.findByLabelText('Dokumentet (opsionale)'), { target: { files } }) }
beforeEach(() => {
  vi.resetAllMocks()
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
  getStudentTask.mockResolvedValue(task)
  getSupervisorTask.mockResolvedValue({ ...task, status: 'SUBMITTED', can_submit: false, has_submissions: true })
  listTaskSubmissions.mockResolvedValue({ data: [submission], meta })
})
afterEach(() => routers.splice(0).forEach((memory) => memory.dispose()))

it('selects multiple files, shows sizes, and removes a file before explicit submission', async () => {
  renderPage()
  const pdf = new File(['pdf'], 'Report.pdf', { type: 'application/pdf' })
  const zip = new File(['zip'], 'Project.zip', { type: 'application/zip' })
  await select([pdf, zip])
  expect(screen.getByText('Report.pdf — 0.0 KB')).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Hiq Project.zip' }))
  expect(screen.queryByText('Project.zip — 0.0 KB')).not.toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Puna e përfunduar *'), { target: { value: 'Completed work' } })
  let finish
  submitTask.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  fireEvent.click(screen.getByRole('button', { name: 'Dorëzo punën', exact: true }))
  const dialog = screen.getByRole('dialog')
  expect(dialog).toHaveTextContent('Report.pdf')
  fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo dorëzimin' }))
  fireEvent.click(within(dialog).getByRole('button', { name: 'Duke dorëzuar…' }))
  expect(submitTask).toHaveBeenCalledTimes(1)
  expect(submitTask).toHaveBeenCalledWith(3, { submission_text: 'Completed work', resource_url: null, files: [pdf] }, expect.any(Function))
  getStudentTask.mockResolvedValue({ ...task, status: 'SUBMITTED', can_submit: false, has_submissions: true }); finish(task)
  await screen.findByRole('heading', { name: 'Dorëzimi 1' })
})

it('rejects too many, oversized, and unsupported selections without retaining them', async () => {
  renderPage()
  await select(Array.from({ length: 6 }, () => new File(['x'], 'work.pdf')))
  expect(screen.getByRole('alert')).toHaveTextContent('5 dokumente')
  await select([new File(['x'], 'work.php')]); expect(screen.getByRole('alert')).toHaveTextContent('PDF, DOCX')
  const large = new File(['x'], 'large.pdf'); Object.defineProperty(large, 'size', { value: 10 * 1024 * 1024 + 1 })
  await select([large]); expect(screen.getByRole('alert')).toHaveTextContent('10 MB')
  expect(submitTask).not.toHaveBeenCalled()
})

it('retains files and surfaces server file errors after failed upload', async () => {
  submitTask.mockRejectedValue({ status: 422, validationErrors: { 'files.0': ['Content does not match extension'] } })
  renderPage(); await select([new File(['pdf'], 'Report.pdf')])
  fireEvent.change(screen.getByLabelText('Puna e përfunduar *'), { target: { value: 'Work' } })
  fireEvent.click(screen.getByRole('button', { name: 'Dorëzo punën', exact: true })); fireEvent.click(screen.getByRole('button', { name: 'Konfirmo dorëzimin' }))
  await screen.findByText('Content does not match extension')
  expect(screen.getByRole('button', { name: 'Hiq Report.pdf' })).toBeEnabled()
})

it('displays the original full HTTPS URL and version-specific downloadable metadata to both roles', async () => {
  getStudentTask.mockResolvedValue({ ...task, status: 'SUBMITTED', can_submit: false, has_submissions: true })
  listTaskSubmissions.mockResolvedValue({ data: [{ ...submission, id: 12, version_no: 2, files: [{ ...file, id: 22, original_name: 'Report_v2.pdf' }] }, submission], meta })
  renderPage(); const links = await screen.findAllByRole('link', { name: url, exact: true })
  expect(links[0]).toHaveAttribute('href', url); expect(links[0]).toHaveAttribute('title', url)
  expect(links[0]).toHaveAttribute('target', '_blank'); expect(links[0]).toHaveAttribute('rel', 'noopener noreferrer')
  expect(within(screen.getByRole('heading', { name: 'Dorëzimi 2' }).closest('article')).getByRole('link', { name: 'Report_v2.pdf' })).toBeInTheDocument()
  expect(within(screen.getByRole('heading', { name: 'Dorëzimi 1' }).closest('article')).getByRole('link', { name: 'Report_v1.pdf' })).toBeInTheDocument()
  expect(screen.getAllByText('2.0 MB')).toHaveLength(2)
})

it('shows supervisor attachment download failures without altering feedback or task', async () => {
  downloadTaskFile.mockRejectedValue({ status: 404 })
  renderPage({ ...student, role: 'COMPANY_SUPERVISOR' })
  fireEvent.click(await screen.findByRole('link', { name: 'Report_v1.pdf' }))
  await screen.findByRole('alert')
  expect(downloadTaskFile).toHaveBeenCalledExactlyOnceWith(file)
  expect(screen.getByRole('link', { name: url })).toHaveAttribute('href', url)
})

it('resubmission starts without selected prior files and sends only new attachments', async () => {
  getStudentTask.mockResolvedValue({ ...task, status: 'REVISION_REQUIRED', can_submit: false, can_resubmit: true, has_submissions: true })
  listTaskSubmissions.mockResolvedValue({ data: [{ ...submission, feedback: { decision: 'REVISION_REQUIRED', comment: 'Revise', created_at: '2026-10-08T13:00:00Z' } }], meta })
  resubmitTask.mockRejectedValue({ status: 422 })
  renderPage(); fireEvent.click(await screen.findByRole('button', { name: 'Rishiko dhe ridorëzo' }))
  expect(screen.queryByRole('button', { name: 'Hiq Report_v1.pdf' })).not.toBeInTheDocument()
  const pdf = new File(['pdf'], 'Report_v2.pdf'); await select([pdf])
  fireEvent.change(screen.getByLabelText('Lidhja e punës (opsionale)'), { target: { value: '' } })
  fireEvent.click(screen.getByRole('button', { name: 'Ridorëzo punën', exact: true })); fireEvent.click(screen.getByRole('button', { name: 'Konfirmo ridorëzimin' }))
  await waitFor(() => expect(resubmitTask).toHaveBeenCalledWith(3, { submission_text: 'Original work', resource_url: null, expected_submission_id: 11, files: [pdf] }, expect.any(Function)))
})
