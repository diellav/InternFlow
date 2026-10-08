import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getInternship, getInternshipCompanies, getInternshipSupervisors, listCoordinatorInternships, listInternships, resubmitInternship, saveInternship, submitInternship } from './api/internshipsApi'

vi.mock('./api/internshipsApi', async (importOriginal) => ({ ...await importOriginal(), getInternship: vi.fn(), getInternshipCompanies: vi.fn(), getInternshipSupervisors: vi.fn(), listCoordinatorInternships: vi.fn(), listInternships: vi.fn(), resubmitInternship: vi.fn(), saveInternship: vi.fn(), submitInternship: vi.fn() }))

const student = { id: 8, first_name: 'Ada', last_name: 'Student', role: 'STUDENT', is_active: true }
const revision = { id: 7, position_title: 'Software Intern', description: 'Original duties', company_id: 4, company_supervisor_id: 5, start_date: '2026-11-01', end_date: '2026-12-01', status: 'REVISION_REQUIRED', decision_comment: 'Clarify responsibilities.\nKeep these exact instructions.', submitted_at: '2026-10-01T12:00:00Z', coordinator_id: 9, company: { id: 4, name: 'Acme' }, supervisor: { user_id: 5, first_name: 'Drita', last_name: 'Mentor' }, coordinator: { first_name: 'Grace', last_name: 'Coordinator', academic_unit: 'Engineering' } }
const submitted = { ...revision, status: 'SUBMITTED', decision_comment: null, submitted_at: '2026-10-08T12:00:00Z' }
const refreshUser = vi.fn()
const routers = []
function renderPage(path = '/student/internships/7', user = student) {
  const memoryRouter = createMemoryRouter(router.routes, { initialEntries: [path] })
  routers.push(memoryRouter)
  render(<AuthContext.Provider value={{ user, isAuthenticated: true, isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memoryRouter} /></AuthContext.Provider>)
  return memoryRouter
}
beforeEach(() => {
  vi.resetAllMocks()
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
  getInternship.mockResolvedValue(revision)
  getInternshipCompanies.mockResolvedValue([{ id: 4, name: 'Acme' }])
  getInternshipSupervisors.mockResolvedValue([{ user_id: 5, first_name: 'Drita', last_name: 'Mentor' }])
  refreshUser.mockResolvedValue(student)
})
afterEach(() => routers.splice(0).forEach((memoryRouter) => memoryRouter.dispose()))

it('shows exact current instructions, coordinator and separate edit/resubmit actions', async () => {
  renderPage()
  await screen.findByRole('button', { name: 'Ridorëzo për shqyrtim' })
  const summary = screen.getByRole('region', { name: 'Gjendja e shqyrtimit' })
  expect(summary.querySelector('.internship-decision-comment').textContent).toBe(revision.decision_comment)
  expect(summary).toHaveTextContent('Vetëm ruajtja nuk e kthen aplikimin në radhën e shqyrtimit')
  expect(summary).toHaveTextContent('jo historiku i shqyrtimeve')
  expect(screen.getByRole('link', { name: 'Ndrysho aplikimin' })).toHaveAttribute('href', '/student/internships/7/edit')
  expect(screen.getByText('Grace Coordinator')).toBeInTheDocument()
})

it('saves corrections on the same record without either submission call and preserves instructions', async () => {
  const updated = { ...revision, description: 'Corrected duties' }
  saveInternship.mockResolvedValue(updated)
  renderPage('/student/internships/7/edit')
  const description = await screen.findByLabelText('Përshkrimi (opsional)')
  expect(description).toHaveValue('Original duties')
  expect(screen.getByRole('region', { name: 'Gjendja e shqyrtimit' })).toHaveTextContent('Keep these exact instructions.')
  fireEvent.change(description, { target: { value: 'Discard me' } })
  fireEvent.click(screen.getByRole('button', { name: 'Rivendos fushat' }))
  expect(description).toHaveValue('Original duties')
  fireEvent.change(description, { target: { value: 'Corrected duties' } })
  await screen.findByRole('option', { name: 'Acme' })
  getInternship.mockResolvedValue(updated)
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj korrigjimet' }))
  await screen.findByText(/Korrigjimet u ruajtën/)
  expect(saveInternship).toHaveBeenCalledWith(7, { position_title: 'Software Intern', description: 'Corrected duties', company_id: 4, company_supervisor_id: 5, start_date: '2026-11-01', end_date: '2026-12-01' })
  expect(resubmitInternship).not.toHaveBeenCalled()
  expect(submitInternship).not.toHaveBeenCalled()
  expect(screen.getByRole('button', { name: 'Ridorëzo për shqyrtim' })).toBeInTheDocument()
  expect(screen.getByRole('region', { name: 'Gjendja e shqyrtimit' })).toHaveTextContent('Clarify responsibilities.')
})

it('preserves corrected form values and instructions on validation failure, then reloads stale edit state', async () => {
  saveInternship.mockRejectedValueOnce({ status: 422, validationErrors: { description: ['Description too long'] } }).mockRejectedValueOnce({ status: 409 })
  renderPage('/student/internships/7/edit')
  fireEvent.change(await screen.findByLabelText('Përshkrimi (opsional)'), { target: { value: 'Saved locally' } })
  await screen.findByRole('option', { name: 'Acme' })
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj korrigjimet' }))
  await screen.findByText('Description too long')
  expect(screen.getByLabelText('Përshkrimi (opsional)')).toHaveValue('Saved locally')
  expect(screen.getByRole('region', { name: 'Gjendja e shqyrtimit' })).toHaveTextContent('Clarify responsibilities.')
  getInternship.mockResolvedValue(submitted)
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj korrigjimet' }))
  await screen.findByText('Aplikim vetëm për lexim')
  expect(screen.queryByRole('button', { name: 'Ridorëzo për shqyrtim' })).not.toBeInTheDocument()
})

it('confirms and cancels resubmission without a request, using accessible modal descriptions', async () => {
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Ridorëzo për shqyrtim' }))
  const dialog = screen.getByRole('dialog', { name: 'Ridorëzoni aplikimin për shqyrtim?' })
  expect(dialog).toHaveAccessibleDescription(/të njëjtit koordinator/)
  expect(dialog).toHaveTextContent('Nuk mund ta ndryshoni gjatë pritjes ose shqyrtimit')
  expect(within(dialog).getByRole('button', { name: 'Anulo' })).toHaveFocus()
  fireEvent.click(within(dialog).getByRole('button', { name: 'Anulo' }))
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(resubmitInternship).not.toHaveBeenCalled()
})

it('resubmits only once, does not change status optimistically and reloads the submitted record', async () => {
  let finish
  resubmitInternship.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Ridorëzo për shqyrtim' }))
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo ridorëzimin' }))
  fireEvent.submit(screen.getByRole('dialog').querySelector('form'))
  expect(screen.getByRole('button', { name: 'Duke dorëzuar…' })).toBeDisabled()
  expect(screen.getByRole('button', { name: 'Anulo' })).toBeDisabled()
  expect(resubmitInternship).toHaveBeenCalledTimes(1)
  expect(screen.getByRole('region', { name: 'Gjendja e shqyrtimit' })).toHaveTextContent('Clarify responsibilities.')
  getInternship.mockResolvedValue(submitted)
  finish(submitted)
  await waitFor(() => expect(getInternship).toHaveBeenCalledTimes(2))
  await waitFor(() => expect(screen.getByText('Aplikim vetëm për lexim')).toBeInTheDocument())
  expect(screen.getByText('I dorëzuar', { selector: '.internship-status' })).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'Ndrysho aplikimin' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Ridorëzo për shqyrtim' })).not.toBeInTheDocument()
  expect(screen.queryByText('Udhëzimet për korrigjim')).not.toBeInTheDocument()
  expect(resubmitInternship).toHaveBeenCalledWith(7)
})

it('shows eligibility errors without losing instructions and links directly back to corrections', async () => {
  resubmitInternship.mockRejectedValue({ status: 422, validationErrors: { company_supervisor_id: ['Supervisor no longer eligible'] } })
  const memoryRouter = renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Ridorëzo për shqyrtim' }))
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo ridorëzimin' }))
  expect(await screen.findByRole('alert')).toHaveTextContent('Mbikëqyrësi: Supervisor no longer eligible')
  expect(screen.getByRole('button', { name: 'Konfirmo ridorëzimin' })).toBeEnabled()
  expect(screen.getByRole('region', { name: 'Gjendja e shqyrtimit' })).toHaveTextContent('Clarify responsibilities.')
  fireEvent.click(screen.getByRole('link', { name: 'Kthehu te korrigjimet' }))
  await screen.findByRole('button', { name: 'Ruaj korrigjimet' })
  expect(memoryRouter.state.location.pathname).toBe('/student/internships/7/edit')
})

it('does not retain a stale saved-but-not-resubmitted notice after successful resubmission or refresh', async () => {
  const memoryRouter = renderPage()
  await screen.findByRole('button', { name: 'Ridorëzo për shqyrtim' })
  await memoryRouter.navigate('/student/internships/7', { state: { notice: 'Saved corrections, not yet resubmitted.' } })
  expect(await screen.findByText('Saved corrections, not yet resubmitted.')).toBeInTheDocument()
  resubmitInternship.mockResolvedValue(submitted)
  fireEvent.click(screen.getByRole('button', { name: 'Ridorëzo për shqyrtim' }))
  getInternship.mockResolvedValue(submitted)
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo ridorëzimin' }))
  await waitFor(() => expect(getInternship).toHaveBeenCalledTimes(2))
  await waitFor(() => expect(screen.getByText('Aplikim vetëm për lexim')).toBeInTheDocument())
  expect(screen.queryByText('Saved corrections, not yet resubmitted.')).not.toBeInTheDocument()
  await memoryRouter.navigate('/student/internships/7/edit')
  await screen.findByRole('alert')
  expect(screen.queryByText('Saved corrections, not yet resubmitted.')).not.toBeInTheDocument()
})

it('refreshes stale resubmission state and removes both edit and resubmit actions', async () => {
  resubmitInternship.mockRejectedValue({ status: 409 })
  renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Ridorëzo për shqyrtim' }))
  getInternship.mockResolvedValue(submitted)
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo ridorëzimin' }))
  await screen.findByText('Aplikim vetëm për lexim')
  expect(screen.getByText(/Statusi i aplikimit ka ndryshuar/)).toBeInTheDocument()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'Ndrysho aplikimin' })).not.toBeInTheDocument()
})

it.each(['SUBMITTED', 'UNDER_REVIEW', 'APPROVED', 'REJECTED', 'ACTIVE', 'COMPLETED'])('keeps %s read-only with no revision actions', async (status) => {
  getInternship.mockResolvedValue({ ...submitted, status })
  renderPage()
  await screen.findByText('Aplikim vetëm për lexim')
  expect(screen.queryByRole('link', { name: 'Ndrysho aplikimin' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Ridorëzo për shqyrtim' })).not.toBeInTheDocument()
})

it('does not carry resubmission confirmation to another application after route navigation', async () => {
  const memoryRouter = renderPage()
  fireEvent.click(await screen.findByRole('button', { name: 'Ridorëzo për shqyrtim' }))
  getInternship.mockResolvedValue({ ...submitted, id: 8, position_title: 'Another application' })
  await memoryRouter.navigate('/student/internships/8')
  await screen.findByRole('heading', { name: 'Another application' })
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(resubmitInternship).not.toHaveBeenCalled()
})

it('offers revision editing from the owned list without creating a duplicate card', async () => {
  listInternships.mockResolvedValue({ data: [revision], meta: { total: 1, current_page: 1, last_page: 1 } })
  renderPage('/student/internships')
  expect(await screen.findByRole('link', { name: 'Ndrysho aplikimin' })).toHaveAttribute('href', '/student/internships/7/edit')
  expect(screen.getAllByRole('article')).toHaveLength(1)
})

it('shows a resubmitted application once in its assigned coordinator inbox with no claim wording', async () => {
  listCoordinatorInternships.mockResolvedValue({ data: [{ ...submitted, student: { first_name: 'Ada', last_name: 'Student', student_number: 'S123', study_program: 'CS', study_year: 2 } }], meta: { total: 1, current_page: 1, last_page: 1 } })
  renderPage('/coordinator/internships', { id: 9, first_name: 'Grace', role: 'ACADEMIC_COORDINATOR', is_active: true })
  await screen.findByRole('link', { name: 'Software Intern' })
  expect(screen.getAllByRole('article')).toHaveLength(1)
  expect(screen.getByText('I caktuar për ju')).toBeInTheDocument()
  expect(screen.queryByText('I pacaktuar · I disponueshëm për marrje')).not.toBeInTheDocument()
})
