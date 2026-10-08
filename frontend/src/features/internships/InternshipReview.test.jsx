import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { decideInternship, getCoordinatorInternship, getInternship, listCoordinatorInternships, startInternshipReview } from './api/internshipsApi'

vi.mock('./api/internshipsApi', async (importOriginal) => ({ ...await importOriginal(), decideInternship: vi.fn(), getCoordinatorInternship: vi.fn(), getInternship: vi.fn(), listCoordinatorInternships: vi.fn(), startInternshipReview: vi.fn() }))

const coordinator = { id: 9, first_name: 'Grace', last_name: 'Coordinator', role: 'ACADEMIC_COORDINATOR', is_active: true }
const student = { id: 8, first_name: 'Ada', last_name: 'Student', role: 'STUDENT', is_active: true }
const submitted = { id: 7, position_title: 'Software Intern', description: 'Academic placement', status: 'SUBMITTED', coordinator_id: 9, submitted_at: '2026-10-08T12:00:00Z', approved_at: null, decision_comment: null, start_date: '2026-11-01', end_date: '2026-12-01', company: { id: 4, name: 'Acme', industry: 'IT' }, supervisor: { user_id: 5, first_name: 'Drita', last_name: 'Mentor', job_title: 'Engineer' }, coordinator: { first_name: 'Grace', last_name: 'Coordinator', academic_unit: 'Engineering' }, student: { first_name: 'Ada', last_name: 'Student', student_number: 'S123', study_program: 'Computer Science', study_year: 2 } }
const reviewing = { ...submitted, status: 'UNDER_REVIEW' }
const approved = { ...submitted, status: 'APPROVED', approved_at: '2026-10-08T13:00:00Z' }
const refreshUser = vi.fn()
const routers = []
function renderPage(path = '/coordinator/internships/7', user = coordinator) {
  const memoryRouter = createMemoryRouter(router.routes, { initialEntries: [path] })
  routers.push(memoryRouter)
  render(<AuthContext.Provider value={{ user, isAuthenticated: true, isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memoryRouter} /></AuthContext.Provider>)
  return memoryRouter
}
beforeEach(() => {
  vi.resetAllMocks()
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
  getCoordinatorInternship.mockResolvedValue(submitted)
  getInternship.mockResolvedValue(submitted)
  refreshUser.mockResolvedValue(coordinator)
})
afterEach(() => routers.splice(0).forEach((memoryRouter) => memoryRouter.dispose()))

it('requires assigned SUBMITTED state and never starts review on detail loading', async () => {
  renderPage()
  expect(await screen.findByRole('button', { name: 'Fillo shqyrtimin' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Mirato', exact: true })).not.toBeInTheDocument()
  expect(startInternshipReview).not.toHaveBeenCalled()
})

it('unassigned applications offer claim only, not review or decisions', async () => {
  getCoordinatorInternship.mockResolvedValue({ ...submitted, coordinator_id: null, coordinator: null })
  renderPage()
  await screen.findByRole('button', { name: 'Merr për shqyrtim' })
  expect(screen.queryByRole('button', { name: 'Fillo shqyrtimin' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Mirato', exact: true })).not.toBeInTheDocument()
})

it('does not show review actions for another coordinator assignment', async () => {
  getCoordinatorInternship.mockResolvedValue({ ...reviewing, coordinator_id: 99 })
  renderPage()
  await screen.findByRole('heading', { name: 'Software Intern' })
  expect(screen.queryByRole('button', { name: 'Mirato', exact: true })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Refuzo', exact: true })).not.toBeInTheDocument()
})

it('confirms start review, guards duplicates and refreshes into decision controls', async () => {
  let finish
  startInternshipReview.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Fillo shqyrtimin' }))
  expect(screen.getByRole('dialog')).toHaveTextContent('nuk regjistron një vendim')
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo', exact: true }))
  expect(screen.getByRole('button', { name: 'Duke ruajtur…' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'Anulo' })).toBeDisabled()
  getCoordinatorInternship.mockResolvedValue(reviewing)
  finish(reviewing)
  expect(await screen.findByRole('button', { name: 'Mirato', exact: true })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Refuzo', exact: true })).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Kërko korrigjime', exact: true })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Fillo shqyrtimin' })).not.toBeInTheDocument()
  expect(startInternshipReview).toHaveBeenCalledTimes(1)
  expect(startInternshipReview).toHaveBeenCalledWith(7)
  expect(getCoordinatorInternship).toHaveBeenCalledTimes(2)
})

it('allows cancelling approval and sends an explicit decision with no protected fields', async () => {
  getCoordinatorInternship.mockResolvedValue(reviewing)
  decideInternship.mockResolvedValue(approved)
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Mirato', exact: true }))
  expect(screen.getByRole('dialog')).toHaveTextContent('nuk aktivizon praktikën')
  fireEvent.click(screen.getByRole('button', { name: 'Anulo' }))
  expect(decideInternship).not.toHaveBeenCalled()
  fireEvent.click(screen.getByRole('button', { name: 'Mirato', exact: true }))
  getCoordinatorInternship.mockResolvedValue(approved)
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo', exact: true }))
  expect(await screen.findByText('Aplikimi është miratuar. Praktika nuk është aktivizuar automatikisht.')).toBeInTheDocument()
  expect(decideInternship).toHaveBeenCalledWith(7, { decision: 'APPROVED' })
  expect(screen.queryByRole('button', { name: 'Mirato', exact: true })).not.toBeInTheDocument()
  expect(screen.getByText(/Miratuar më:/)).toBeInTheDocument()
})

it.each([['REJECTED', 'Refuzo', 'Arsyeja e refuzimit *'], ['REVISION_REQUIRED', 'Kërko korrigjime', 'Udhëzimet për korrigjim *']])('validates and trims the required explanation for %s', async (decision, button, label) => {
  getCoordinatorInternship.mockResolvedValue(reviewing)
  const updated = { ...reviewing, status: decision, decision_comment: 'Explain the correction.' }
  decideInternship.mockResolvedValue(updated)
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: button, exact: true }))
  fireEvent.change(screen.getByLabelText(label), { target: { value: '   ' } })
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo', exact: true }))
  expect(screen.getByText('Shkruani një shpjegim jo të zbrazët.')).toBeInTheDocument()
  expect(screen.getByLabelText(label)).toHaveFocus()
  expect(decideInternship).not.toHaveBeenCalled()
  fireEvent.change(screen.getByLabelText(label), { target: { value: '  Explain the correction.  ' } })
  getCoordinatorInternship.mockResolvedValue(updated)
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo', exact: true }))
  await waitFor(() => expect(getCoordinatorInternship).toHaveBeenCalledTimes(2))
  await waitFor(() => expect(screen.getByText('Explain the correction.')).toBeInTheDocument())
  expect(decideInternship).toHaveBeenCalledWith(7, { decision, decision_comment: 'Explain the correction.' })
  expect(screen.queryByRole('button', { name: button, exact: true })).not.toBeInTheDocument()
})

it('keeps reason text on backend validation errors and displays approval eligibility errors', async () => {
  getCoordinatorInternship.mockResolvedValue(reviewing)
  decideInternship.mockRejectedValueOnce({ status: 422, validationErrors: { decision_comment: ['Invalid explanation'] } })
  decideInternship.mockRejectedValueOnce({ status: 422, validationErrors: { company_id: ['Company no longer eligible'] } })
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Refuzo', exact: true }))
  fireEvent.change(screen.getByLabelText('Arsyeja e refuzimit *'), { target: { value: 'My explanation' } })
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo', exact: true }))
  expect(await screen.findByText('Invalid explanation')).toBeInTheDocument()
  expect(screen.getByLabelText('Arsyeja e refuzimit *')).toHaveValue('My explanation')
  fireEvent.click(screen.getByRole('button', { name: 'Anulo' }))
  fireEvent.click(screen.getByRole('button', { name: 'Mirato', exact: true }))
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo', exact: true }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Kompania: Company no longer eligible')
  expect(screen.getByRole('dialog')).toBeInTheDocument()
})

it('reloads after a stale decision and removes inappropriate controls', async () => {
  getCoordinatorInternship.mockResolvedValue(reviewing)
  decideInternship.mockRejectedValue({ status: 409 })
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Mirato', exact: true }))
  getCoordinatorInternship.mockResolvedValue(approved)
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo', exact: true }))
  expect(await screen.findByText(/Statusi ose caktimi i aplikimit ka ndryshuar/)).toBeInTheDocument()
  await screen.findByText('Aplikimi është miratuar. Praktika nuk është aktivizuar automatikisht.')
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Mirato', exact: true })).not.toBeInTheDocument()
})

it('reloads stale start-review state and handles a lost assignment without showing record data', async () => {
  startInternshipReview.mockRejectedValue({ status: 409 })
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Fillo shqyrtimin' }))
  getCoordinatorInternship.mockRejectedValue({ status: 404 })
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo', exact: true }))
  expect(await screen.findByRole('alert')).toHaveTextContent('nuk u gjet')
  expect(screen.queryByRole('heading', { name: 'Software Intern' })).not.toBeInTheDocument()
})

it.each(['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'REVISION_REQUIRED'])('shows student review status %s with no draft edit actions', async (status) => {
  getInternship.mockResolvedValue({ ...submitted, status, decision_comment: ['REJECTED', 'REVISION_REQUIRED'].includes(status) ? 'Required explanation' : null, approved_at: status === 'APPROVED' ? approved.approved_at : null })
  renderPage('/student/internships/7', student)
  await screen.findByText(status === 'REVISION_REQUIRED' ? 'Aplikim për korrigjim' : 'Aplikim vetëm për lexim')
  const summary = screen.getByRole('region', { name: 'Gjendja e shqyrtimit' })
  expect(summary).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'Ndrysho draftin' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Dorëzo për miratim' })).not.toBeInTheDocument()
  if (status === 'REVISION_REQUIRED') {
    expect(summary).toHaveTextContent('ridorëzojeni veçmas')
    expect(screen.getByRole('link', { name: 'Ndrysho aplikimin' })).toBeInTheDocument()
    expect(screen.getByRole('button', { name: 'Ridorëzo për shqyrtim' })).toBeInTheDocument()
  }
  if (['REJECTED', 'REVISION_REQUIRED'].includes(status)) expect(within(summary).getByText('Required explanation')).toBeInTheDocument()
  if (status === 'APPROVED') expect(summary).toHaveTextContent('nuk është aktivizuar automatikisht')
})

it('does not carry a review dialog to another application when the route ID changes', async () => {
  getCoordinatorInternship.mockResolvedValue(reviewing)
  const memoryRouter = renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Mirato', exact: true }))
  getCoordinatorInternship.mockResolvedValue({ ...submitted, id: 8, position_title: 'Another application' })
  await memoryRouter.navigate('/coordinator/internships/8')
  await screen.findByRole('heading', { name: 'Another application' })
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(decideInternship).not.toHaveBeenCalled()
})

it('keeps all assigned review outcomes in the coordinator inbox', async () => {
  listCoordinatorInternships.mockResolvedValue({ data: ['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'REVISION_REQUIRED'].map((status, index) => ({ ...submitted, id: index + 1, status })), meta: { total: 5, current_page: 1, last_page: 1 } })
  renderPage('/coordinator/internships')
  await screen.findAllByRole('link', { name: 'Software Intern' })
  for (const label of ['I dorëzuar', 'Në shqyrtim', 'I miratuar', 'I refuzuar', 'Kërkohet korrigjim']) expect(screen.getByText(label, { selector: '.internship-status' })).toBeInTheDocument()
  expect(screen.getAllByText('I caktuar për ju')).toHaveLength(5)
})
