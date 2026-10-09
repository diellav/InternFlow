import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getInternship } from '../internships/api/internshipsApi'
import { activateInternship, getStudentTask, getSupervisorInternship, getSupervisorTask, listSupervisorInternships, listTasks, saveTask } from './api/tasksApi'

vi.mock('./api/tasksApi', async (importOriginal) => ({ ...await importOriginal(), activateInternship: vi.fn(), getStudentTask: vi.fn(), getSupervisorInternship: vi.fn(), getSupervisorTask: vi.fn(), listSupervisorInternships: vi.fn(), listTasks: vi.fn(), saveTask: vi.fn() }))
vi.mock('../internships/api/internshipsApi', async (importOriginal) => ({ ...await importOriginal(), getInternship: vi.fn() }))

const supervisor = { id: 9, first_name: 'Drita', last_name: 'Mentor', role: 'COMPANY_SUPERVISOR', is_active: true, profile: { verification_status: 'APPROVED' } }
const student = { id: 8, first_name: 'Ada', last_name: 'Student', role: 'STUDENT', is_active: true }
const approved = { id: 7, position_title: 'Web Internship', description: 'Internship responsibilities', status: 'APPROVED', start_date: '2026-10-01', end_date: '2026-10-31', approved_at: '2026-10-01T10:00:00Z', server_date: '2026-10-08', can_activate: true, can_create_tasks: false, student: { first_name: 'Ada', last_name: 'Student', study_program: 'CS' }, company: { id: 4, name: 'Acme' }, coordinator: { first_name: 'Grace', last_name: 'Coordinator' } }
const active = { ...approved, status: 'ACTIVE', can_activate: false, can_create_tasks: true }
const task = { id: 3, title: 'Build the form', description: 'Validate inputs and test the form.', priority: 'MEDIUM', status: 'ASSIGNED', due_date: '2026-10-20', can_edit: true, created_at: '2026-10-08T10:00:00Z', updated_at: '2026-10-08T10:00:00Z', internship: { ...active, student: { first_name: 'Ada', last_name: 'Student' } } }
const meta = { total: 1, current_page: 1, last_page: 1 }
const refreshUser = vi.fn()
const routers = []
function renderPage(path = '/supervisor/internships/7', user = supervisor, authenticated = true) {
  const memoryRouter = createMemoryRouter(router.routes, { initialEntries: [path] })
  routers.push(memoryRouter)
  render(<AuthContext.Provider value={{ user, isAuthenticated: authenticated, isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memoryRouter} /></AuthContext.Provider>)
  return memoryRouter
}
beforeEach(() => {
  vi.resetAllMocks()
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
  getSupervisorInternship.mockResolvedValue(approved)
  getSupervisorTask.mockResolvedValue(task)
  getStudentTask.mockResolvedValue({ ...task, can_edit: false })
  getInternship.mockResolvedValue(active)
  listSupervisorInternships.mockResolvedValue({ data: [approved], meta })
  listTasks.mockResolvedValue({ data: [], meta: { ...meta, total: 0 } })
  refreshUser.mockResolvedValue(supervisor)
})
afterEach(() => routers.splice(0).forEach((memoryRouter) => memoryRouter.dispose()))

it('shows assigned internships and preserves supervisor verification/profile navigation, search and filtering', async () => {
  renderPage('/supervisor/internships')
  await screen.findByRole('link', { name: 'Web Internship' })
  expect(screen.getByRole('navigation', { name: 'Navigimi i mbikëqyrësit' })).toHaveTextContent('Verifikimi')
  expect(screen.getByRole('link', { name: 'Profili', exact: true })).toHaveAttribute('href', '/profile')
  fireEvent.change(screen.getByLabelText('Statusi i praktikës'), { target: { value: 'APPROVED' } })
  await waitFor(() => expect(listSupervisorInternships).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'APPROVED', page: 1 }), expect.any(AbortSignal)))
  fireEvent.change(screen.getByLabelText('Kërko student ose pozitë'), { target: { value: 'Ada' } })
  fireEvent.click(screen.getByRole('button', { name: 'Kërko', exact: true }))
  await waitFor(() => expect(listSupervisorInternships).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Ada' }), expect.any(AbortSignal)))
})

it('does not automatically activate; confirmation can be cancelled with accessible modal labels', async () => {
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Fillo praktikën' }))
  const dialog = screen.getByRole('dialog', { name: 'Filloni praktikën?' })
  expect(dialog).toHaveAccessibleDescription(/Datat dhe miratimi akademik nuk ndryshojnë/)
  fireEvent.click(within(dialog).getByRole('button', { name: 'Anulo' }))
  expect(activateInternship).not.toHaveBeenCalled()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('activates once and reloads server state without an optimistic status change', async () => {
  let finish
  activateInternship.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Fillo praktikën' }))
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo fillimin' }))
  fireEvent.submit(screen.getByRole('dialog').querySelector('form'))
  expect(screen.getByRole('button', { name: 'Duke filluar…' })).toBeDisabled()
  expect(screen.getByText('I miratuar', { selector: '.internship-status' })).toBeInTheDocument()
  getSupervisorInternship.mockResolvedValue(active)
  finish(active)
  await screen.findByRole('link', { name: 'Krijo detyrë' })
  expect(activateInternship).toHaveBeenCalledExactlyOnceWith(7)
  expect(getSupervisorInternship).toHaveBeenCalledTimes(2)
  expect(screen.queryByRole('button', { name: 'Fillo praktikën' })).not.toBeInTheDocument()
})

it('handles activation validation and stale conflicts with a fresh record', async () => {
  activateInternship.mockRejectedValueOnce({ status: 422, validationErrors: { internship: ['Outside the allowed date range'] } }).mockRejectedValueOnce({ status: 409 })
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Fillo praktikën' }))
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo fillimin' }))
  await screen.findByText('Outside the allowed date range')
  expect(screen.getByRole('button', { name: 'Konfirmo fillimin' })).toBeEnabled()
  getSupervisorInternship.mockResolvedValue(active)
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo fillimin' }))
  await screen.findByRole('link', { name: 'Krijo detyrë' })
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.getByText(/Gjendja ka ndryshuar/)).toBeInTheDocument()
})

it('does not offer activation outside server-provided eligibility or leak an open dialog across record IDs', async () => {
  const memoryRouter = renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Fillo praktikën' }))
  getSupervisorInternship.mockResolvedValue({ ...approved, id: 8, can_activate: false, activation_reason: 'Before start date', position_title: 'Later internship' })
  await memoryRouter.navigate('/supervisor/internships/8')
  await screen.findByRole('heading', { name: 'Later internship' })
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Fillo praktikën' })).not.toBeInTheDocument()
  expect(screen.getByText(/Before start date/)).toBeInTheDocument()
})

it('validates task creation and sends only schema-supported editable fields', async () => {
  getSupervisorInternship.mockResolvedValue(active)
  saveTask.mockResolvedValue(task)
  renderPage('/supervisor/internships/7/tasks/new')
  fireEvent.click(await screen.findByRole('button', { name: 'Krijo detyrën' }))
  expect(screen.getByLabelText('Titulli i detyrës *')).toHaveFocus()
  expect(saveTask).not.toHaveBeenCalled()
  fireEvent.change(screen.getByLabelText('Titulli i detyrës *'), { target: { value: '  Build the form  ' } })
  fireEvent.change(screen.getByLabelText('Përshkrimi i detyrës *'), { target: { value: '  Validate inputs and test the form.  ' } })
  fireEvent.click(screen.getByRole('button', { name: 'Krijo detyrën' }))
  await screen.findByText('Detyra u krijua dhe iu caktua studentit.')
  expect(saveTask).toHaveBeenCalledWith(7, undefined, { title: 'Build the form', description: 'Validate inputs and test the form.', priority: 'MEDIUM', due_date: null })
})

it('keeps task values on validation errors and prevents duplicate saves', async () => {
  getSupervisorInternship.mockResolvedValue(active)
  saveTask.mockRejectedValueOnce({ status: 422, validationErrors: { title: ['Invalid task title'] } })
  let finish
  saveTask.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve }))
  renderPage('/supervisor/internships/7/tasks/new')
  fireEvent.change(await screen.findByLabelText('Titulli i detyrës *'), { target: { value: 'My task' } })
  fireEvent.change(screen.getByLabelText('Përshkrimi i detyrës *'), { target: { value: 'Responsibilities' } })
  fireEvent.click(screen.getByRole('button', { name: 'Krijo detyrën' }))
  await screen.findByText('Invalid task title')
  expect(screen.getByLabelText('Titulli i detyrës *')).toHaveValue('My task')
  fireEvent.click(screen.getByRole('button', { name: 'Krijo detyrën' }))
  fireEvent.submit(screen.getByLabelText('Titulli i detyrës *').closest('form'))
  expect(screen.getByRole('button', { name: 'Duke ruajtur…' })).toBeDisabled()
  expect(saveTask).toHaveBeenCalledTimes(2)
  finish(task)
  await screen.findByRole('heading', { name: task.title })
})

it('prefills task edits, supports reset and refreshes details on conflict', async () => {
  saveTask.mockRejectedValue({ status: 409 })
  renderPage('/supervisor/tasks/3/edit')
  const title = await screen.findByLabelText('Titulli i detyrës *')
  expect(title).toHaveValue(task.title)
  fireEvent.change(title, { target: { value: 'Discard' } })
  fireEvent.click(screen.getByRole('button', { name: 'Rivendos fushat' }))
  expect(title).toHaveValue(task.title)
  fireEvent.change(title, { target: { value: 'Corrected task' } })
  getSupervisorTask.mockResolvedValue({ ...task, status: 'SUBMITTED', can_edit: false })
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj detyrën' }))
  await screen.findByText(/Gjendja ka ndryshuar/)
  await screen.findByRole('heading', { name: task.title })
  expect(screen.queryByRole('link', { name: 'Ndrysho detyrën' })).not.toBeInTheDocument()
})

it('saves edits on the same task and blocks direct ineligible edit routes', async () => {
  const updated = { ...task, title: 'Updated task' }
  saveTask.mockResolvedValue(updated)
  const memoryRouter = renderPage('/supervisor/tasks/3/edit')
  fireEvent.change(await screen.findByLabelText('Titulli i detyrës *'), { target: { value: 'Updated task' } })
  getSupervisorTask.mockResolvedValue(updated)
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj detyrën' }))
  await screen.findByText('Detyra u përditësua me sukses.')
  expect(memoryRouter.state.location.pathname).toBe('/supervisor/tasks/3')
  getSupervisorTask.mockResolvedValue({ ...updated, can_edit: false })
  await memoryRouter.navigate('/supervisor/tasks/3/edit')
  await screen.findByRole('alert')
  expect(screen.queryByLabelText('Titulli i detyrës *')).not.toBeInTheDocument()
})

it('refreshes parent details and displays a stale task-creation conflict', async () => {
  getSupervisorInternship.mockResolvedValue(active)
  saveTask.mockRejectedValue({ status: 409 })
  const memoryRouter = renderPage('/supervisor/internships/7/tasks/new')
  fireEvent.change(await screen.findByLabelText('Titulli i detyrës *'), { target: { value: 'New task' } })
  fireEvent.change(screen.getByLabelText('Përshkrimi i detyrës *'), { target: { value: 'Responsibilities' } })
  getSupervisorInternship.mockResolvedValue(approved)
  fireEvent.click(screen.getByRole('button', { name: 'Krijo detyrën' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Gjendja ka ndryshuar')
  expect(memoryRouter.state.location.pathname).toBe('/supervisor/internships/7')
  expect(screen.queryByRole('link', { name: 'Krijo detyrë' })).not.toBeInTheDocument()
})

it('shows tasks on own active student internship and read-only task details', async () => {
  listTasks.mockResolvedValue({ data: [{ ...task, can_edit: false }], meta })
  const memoryRouter = renderPage('/student/internships/7', student)
  fireEvent.click(await screen.findByRole('link', { name: task.title }))
  await screen.findByRole('heading', { name: task.title })
  expect(memoryRouter.state.location.pathname).toBe('/student/tasks/3')
  expect(screen.getByText(/Detyrën e cakton mbikëqyrësi/)).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'Ndrysho detyrën' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Dorëzo|Përfundo/ })).not.toBeInTheDocument()
  expect(getStudentTask).toHaveBeenCalledWith('3', expect.any(AbortSignal))
})

it('shows task list filters and empty/error/retry states', async () => {
  getSupervisorInternship.mockResolvedValue(active)
  listTasks.mockRejectedValueOnce({ status: 500 }).mockResolvedValue({ data: [task], meta })
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Provo përsëri' }))
  await screen.findByRole('link', { name: task.title })
  fireEvent.change(screen.getByLabelText('Statusi i detyrës'), { target: { value: 'ASSIGNED' } })
  await waitFor(() => expect(listTasks).toHaveBeenLastCalledWith('supervisor', 7, expect.objectContaining({ status: 'ASSIGNED' }), expect.any(AbortSignal)))
})

it('handles inaccessible records and operational denial while keeping verification navigation available', async () => {
  getSupervisorInternship.mockRejectedValue({ status: 404 })
  const memoryRouter = renderPage()
  expect(await screen.findByRole('alert')).toHaveTextContent('nuk u gjet')
  listSupervisorInternships.mockRejectedValue({ status: 403 })
  await memoryRouter.navigate('/supervisor/internships')
  await waitFor(() => expect(screen.getByRole('alert')).toHaveTextContent('Nuk keni qasje operative'))
  expect(screen.getByRole('link', { name: 'Shiko verifikimin' })).toHaveAttribute('href', '/supervisor/verification')
  await waitFor(() => expect(refreshUser).toHaveBeenCalled())
})

it('guards supervisor routes from other roles and guests', async () => {
  const memoryRouter = renderPage('/supervisor/internships', student)
  await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/session'))
  expect(listSupervisorInternships).not.toHaveBeenCalled()
})

it('guards student task routes from guests', async () => {
  const memoryRouter = renderPage('/student/tasks/3', null, false)
  await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/login'))
  expect(getStudentTask).not.toHaveBeenCalled()
})
