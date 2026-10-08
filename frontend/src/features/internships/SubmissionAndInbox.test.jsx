import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { claimInternship, getCoordinatorInternship, getInternship, listCoordinatorInternships, submitInternship } from './api/internshipsApi'

vi.mock('./api/internshipsApi', async (importOriginal) => ({ ...await importOriginal(), claimInternship: vi.fn(), getCoordinatorInternship: vi.fn(), getInternship: vi.fn(), listCoordinatorInternships: vi.fn(), submitInternship: vi.fn() }))

const student = { id: 8, first_name: 'Ada', last_name: 'Student', role: 'STUDENT', is_active: true }
const coordinator = { id: 9, first_name: 'Grace', last_name: 'Coordinator', role: 'ACADEMIC_COORDINATOR', is_active: true }
const draft = { id: 7, position_title: 'Software Intern', description: 'Academic placement', company_id: 4, company_supervisor_id: 5, start_date: '2026-11-01', end_date: '2026-12-01', status: 'DRAFT', company: { id: 4, name: 'Acme', industry: 'IT' }, supervisor: { user_id: 5, first_name: 'Drita', last_name: 'Mentor', job_title: 'Engineer' }, coordinator: null }
const submitted = { ...draft, status: 'SUBMITTED', submitted_at: '2026-10-08T12:00:00Z', coordinator_id: null, student: { first_name: 'Ada', last_name: 'Student', student_number: 'S123', study_program: 'Computer Science', study_year: 2 } }
const assigned = { ...submitted, coordinator_id: 9, coordinator: { first_name: 'Grace', last_name: 'Coordinator', academic_unit: 'Engineering' } }
const refreshUser = vi.fn()
const logout = vi.fn()
const routers = []
function renderPage(path, user = student, authenticated = true) {
  const memoryRouter = createMemoryRouter(router.routes, { initialEntries: [path] })
  routers.push(memoryRouter)
  render(<AuthContext.Provider value={{ user, isAuthenticated: authenticated, isLoading: false, refreshUser, logout }}><RouterProvider router={memoryRouter} /></AuthContext.Provider>)
  return memoryRouter
}
beforeEach(() => {
  vi.resetAllMocks()
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
  getInternship.mockResolvedValue(draft)
  getCoordinatorInternship.mockResolvedValue(submitted)
  listCoordinatorInternships.mockResolvedValue({ data: [submitted], meta: { total: 16, current_page: 1, last_page: 2 } })
  claimInternship.mockResolvedValue(assigned)
  refreshUser.mockResolvedValue(student)
  logout.mockResolvedValue()
})
afterEach(() => routers.splice(0).forEach((memoryRouter) => memoryRouter.dispose()))

it('opens and cancels submission confirmation without sending a request', async () => {
  renderPage('/student/internships/7')
  fireEvent.click(await screen.findByRole('button', { name: 'Dorëzo për miratim' }))
  const dialog = screen.getByRole('dialog')
  expect(dialog).toHaveTextContent('redaktimi i zakonshëm i draftit nuk është më i disponueshëm')
  fireEvent.click(within(dialog).getByRole('button', { name: 'Anulo' }))
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(submitInternship).not.toHaveBeenCalled()
})

it('submits once, refreshes details and shows the submitted read-only record', async () => {
  let finish
  submitInternship.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  renderPage('/student/internships/7')
  fireEvent.click(await screen.findByRole('button', { name: 'Dorëzo për miratim' }))
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo dorëzimin' }))
  expect(screen.getByRole('button', { name: 'Duke dorëzuar…' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'Anulo' })).toBeDisabled()
  expect(submitInternship).toHaveBeenCalledWith(7)
  getInternship.mockResolvedValue(submitted)
  finish(submitted)
  await waitFor(() => expect(getInternship).toHaveBeenCalledTimes(2))
  await waitFor(() => expect(screen.getByText('Aplikim vetëm për lexim')).toBeInTheDocument())
  expect(screen.getByText('Aplikimi u dorëzua për shqyrtim akademik.')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Dorëzo për miratim' })).not.toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'Ndrysho draftin' })).not.toBeInTheDocument()
  expect(submitInternship).toHaveBeenCalledTimes(1)
  expect(getInternship).toHaveBeenCalledTimes(2)
})

it('preserves draft details and displays submission eligibility validation errors', async () => {
  submitInternship.mockRejectedValue({ status: 422, validationErrors: { company_supervisor_id: ['A verified supervisor is required.'] } })
  renderPage('/student/internships/7')
  fireEvent.click(await screen.findByRole('button', { name: 'Dorëzo për miratim' }))
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo dorëzimin' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Mbikëqyrësi: A verified supervisor is required.')
  expect(screen.getByRole('button', { name: 'Konfirmo dorëzimin' })).toBeEnabled()
  expect(screen.getByRole('link', { name: 'Ndrysho draftin' })).toBeInTheDocument()
})

it('reloads stale submission state after 409 and removes the draft action', async () => {
  submitInternship.mockRejectedValue({ status: 409 })
  renderPage('/student/internships/7')
  fireEvent.click(await screen.findByRole('button', { name: 'Dorëzo për miratim' }))
  getInternship.mockResolvedValue(submitted)
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo dorëzimin' }))
  expect(await screen.findByText('Aplikim vetëm për lexim')).toBeInTheDocument()
  expect(screen.getByText(/Statusi i aplikimit ka ndryshuar/)).toBeInTheDocument()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('never offers submission on any non-draft application', async () => {
  getInternship.mockResolvedValue({ ...submitted, status: 'REVISION_REQUIRED' })
  renderPage('/student/internships/7')
  await screen.findByText('Aplikim për korrigjim')
  expect(screen.queryByRole('button', { name: 'Dorëzo për miratim' })).not.toBeInTheDocument()
})

it('renders the shared inbox, filters, searches, paginates and links to details', async () => {
  renderPage('/coordinator/internships', coordinator)
  expect(await screen.findByRole('link', { name: 'Software Intern' })).toBeInTheDocument()
  expect(screen.getByText('I pacaktuar · I disponueshëm për marrje')).toBeInTheDocument()
  expect(screen.getByRole('navigation', { name: 'Navigimi i koordinatorit' })).toHaveTextContent('Profili')
  expect(screen.queryByRole('option', { name: 'Draft' })).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
  await waitFor(() => expect(listCoordinatorInternships).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }), expect.any(AbortSignal)))
  fireEvent.change(screen.getByLabelText('Statusi'), { target: { value: 'SUBMITTED' } })
  await waitFor(() => expect(listCoordinatorInternships).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'SUBMITTED', page: 1 }), expect.any(AbortSignal)))
  fireEvent.change(screen.getByLabelText('Kërko aplikime'), { target: { value: 'Ada' } })
  fireEvent.click(screen.getByRole('button', { name: 'Kërko', exact: true }))
  await waitFor(() => expect(listCoordinatorInternships).toHaveBeenLastCalledWith(expect.objectContaining({ search: 'Ada', page: 1 }), expect.any(AbortSignal)))
  fireEvent.click(screen.getByRole('button', { name: 'Pastro filtrat' }))
  await waitFor(() => expect(listCoordinatorInternships).toHaveBeenLastCalledWith({ page: 1, per_page: 15 }, expect.any(AbortSignal)))
  fireEvent.click(screen.getByRole('link', { name: 'Shiko aplikimin →' }))
  expect(await screen.findByRole('heading', { name: 'Studenti dhe informacioni akademik' })).toBeInTheDocument()
  expect(screen.getByText('S123')).toBeInTheDocument()
  expect(screen.getByText('Computer Science')).toBeInTheDocument()
  expect(screen.getByText(/Merreni aplikimin përpara/)).toBeInTheDocument()
})

it('claims once and shows the assigned coordinator without any review decision controls', async () => {
  let finish
  claimInternship.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  renderPage('/coordinator/internships/7', coordinator)
  fireEvent.click(await screen.findByRole('button', { name: 'Merr për shqyrtim' }))
  expect(screen.getByRole('button', { name: 'Duke marrë…' })).toBeDisabled()
  finish(assigned)
  expect(await screen.findByText('Aplikim i caktuar për ju')).toBeInTheDocument()
  expect(screen.getByText('Engineering')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Merr për shqyrtim' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Mirato|Refuzo/ })).not.toBeInTheDocument()
  expect(claimInternship).toHaveBeenCalledTimes(1)
  expect(claimInternship).toHaveBeenCalledWith(7)
})

it('reloads a competing claim and stops displaying an inaccessible record', async () => {
  claimInternship.mockRejectedValue({ status: 409 })
  renderPage('/coordinator/internships/7', coordinator)
  await screen.findByRole('button', { name: 'Merr për shqyrtim' })
  getCoordinatorInternship.mockRejectedValue({ status: 404 })
  fireEvent.click(screen.getByRole('button', { name: 'Merr për shqyrtim' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('nuk u gjet')
  expect(screen.queryByRole('heading', { name: 'Software Intern' })).not.toBeInTheDocument()
  expect(screen.getByText(/nuk është më i disponueshëm për marrje/)).toBeInTheDocument()
})

it('handles inbox loading, retry, empty state and assigned-only cards', async () => {
  let reject
  listCoordinatorInternships.mockImplementationOnce(() => new Promise((resolve, fail) => { reject = fail }))
  listCoordinatorInternships.mockResolvedValueOnce({ data: [], meta: { total: 0, current_page: 1, last_page: 1 } })
  listCoordinatorInternships.mockResolvedValue({ data: [assigned], meta: { total: 1, current_page: 1, last_page: 1 } })
  renderPage('/coordinator/internships', coordinator)
  expect(await screen.findByRole('status')).toHaveTextContent('Duke ngarkuar aplikimet')
  await waitFor(() => expect(reject).toBeDefined())
  reject({ status: 500 })
  fireEvent.click(await screen.findByRole('button', { name: 'Provo përsëri' }))
  await screen.findByRole('heading', { name: 'Nuk ka aplikime të disponueshme' })
  fireEvent.click(screen.getByRole('button', { name: 'Rifresko' }))
  expect(await screen.findByText('I caktuar për ju')).toBeInTheDocument()
})

it('guards coordinator routes from students', async () => {
  const memoryRouter = renderPage('/coordinator/internships', student)
  await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/session'))
  expect(listCoordinatorInternships).not.toHaveBeenCalled()
})

it('guards coordinator routes from guests', async () => {
  const memoryRouter = renderPage('/coordinator/internships', null, false)
  await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/login'))
  expect(listCoordinatorInternships).not.toHaveBeenCalled()
})

it('integrates coordinator logout', async () => {
  const memoryRouter = renderPage('/coordinator/internships', coordinator)
  fireEvent.click(await screen.findByRole('button', { name: 'Dil', exact: true }))
  await waitFor(() => expect(logout).toHaveBeenCalledTimes(1))
  await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/login'))
})
