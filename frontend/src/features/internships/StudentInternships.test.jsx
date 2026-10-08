import { fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getInternship, getInternshipCompanies, getInternshipSupervisors, listInternships, saveInternship } from './api/internshipsApi'

vi.mock('./api/internshipsApi', async (importOriginal) => ({ ...await importOriginal(), getInternship: vi.fn(), getInternshipCompanies: vi.fn(), getInternshipSupervisors: vi.fn(), listInternships: vi.fn(), saveInternship: vi.fn() }))

const student = { id: 8, first_name: 'Ada', last_name: 'Student', role: 'STUDENT', is_active: true }
const draft = { id: 7, position_title: 'Software Intern', description: 'Learning backend development', company_id: 4, company_supervisor_id: 5, start_date: '2026-11-01', end_date: '2026-12-01', status: 'DRAFT', company: { id: 4, name: 'Acme', industry: 'IT' }, supervisor: { user_id: 5, first_name: 'Grace', last_name: 'Mentor', job_title: 'Engineer' }, coordinator: null }
const refreshUser = vi.fn()
const routers = []
function renderPage(path = '/student/internships', user = student, authenticated = true) {
  const memoryRouter = createMemoryRouter(router.routes, { initialEntries: [path] })
  routers.push(memoryRouter)
  render(<AuthContext.Provider value={{ user, isAuthenticated: authenticated, isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memoryRouter} /></AuthContext.Provider>)
  return memoryRouter
}
beforeEach(() => {
  vi.resetAllMocks()
  refreshUser.mockResolvedValue(student)
  listInternships.mockResolvedValue({ data: [draft], meta: { total: 16, current_page: 1, last_page: 2 } })
  getInternship.mockResolvedValue(draft)
  getInternshipCompanies.mockResolvedValue([{ id: 4, name: 'Acme' }, { id: 9, name: 'Other Company' }])
  getInternshipSupervisors.mockImplementation(async (id) => String(id) === '4' ? [{ user_id: 5, first_name: 'Grace', last_name: 'Mentor', job_title: 'Engineer' }] : [{ user_id: 12, first_name: 'Other', last_name: 'Mentor' }])
})
afterEach(() => routers.splice(0).forEach((memoryRouter) => memoryRouter.dispose()))

it('renders an empty list and a meaningful create action', async () => {
  listInternships.mockResolvedValue({ data: [], meta: { total: 0, current_page: 1, last_page: 1 } })
  renderPage()
  expect(await screen.findByRole('heading', { name: 'Praktika juaj fillon këtu' })).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Krijo draftin e parë' })).toHaveAttribute('href', '/student/internships/new')
})

it('loads owned applications, paginates, filters and opens details', async () => {
  renderPage()
  expect(await screen.findByRole('link', { name: 'Software Intern' })).toBeInTheDocument()
  expect(screen.getByText('Acme')).toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
  await waitFor(() => expect(listInternships).toHaveBeenLastCalledWith(expect.objectContaining({ page: 2 }), expect.any(AbortSignal)))
  fireEvent.change(screen.getByLabelText('Statusi i aplikimit'), { target: { value: 'DRAFT' } })
  await waitFor(() => expect(listInternships).toHaveBeenLastCalledWith(expect.objectContaining({ status: 'DRAFT', page: 1 }), expect.any(AbortSignal)))
  fireEvent.click(screen.getByRole('link', { name: 'Shiko detajet →' }))
  expect(await screen.findByRole('heading', { name: 'Software Intern', exact: true })).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Ndrysho draftin' })).toHaveAttribute('href', '/student/internships/7/edit')
})

it('validates minimum draft fields, selects a company and its supervisor, and saves without protected fields', async () => {
  let finish
  saveInternship.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  const memoryRouter = renderPage('/student/internships/new')
  await screen.findByRole('option', { name: 'Acme' })
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj si draft' }))
  expect(screen.getAllByText('Kjo fushë është e detyrueshme edhe për draftin.')).toHaveLength(4)
  expect(saveInternship).not.toHaveBeenCalled()
  fireEvent.change(screen.getByLabelText('Titulli i pozitës *'), { target: { value: 'Software Intern' } })
  fireEvent.change(screen.getByLabelText('Kompania *'), { target: { value: '4' } })
  await screen.findByRole('option', { name: 'Grace Mentor · Engineer' })
  fireEvent.change(screen.getByLabelText('Mbikëqyrësi (opsional)'), { target: { value: '5' } })
  fireEvent.change(screen.getByLabelText('Data e fillimit *'), { target: { value: '2026-11-01' } })
  fireEvent.change(screen.getByLabelText('Data e përfundimit *'), { target: { value: '2026-10-01' } })
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj si draft' }))
  expect(screen.getByText('Data e përfundimit nuk mund të jetë para datës së fillimit.')).toBeInTheDocument()
  fireEvent.change(screen.getByLabelText('Data e përfundimit *'), { target: { value: '2026-12-01' } })
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj si draft' }))
  expect(saveInternship).toHaveBeenCalledWith(undefined, { position_title: 'Software Intern', description: null, company_id: 4, company_supervisor_id: 5, start_date: '2026-11-01', end_date: '2026-12-01' })
  expect(screen.getByRole('button', { name: 'Duke ruajtur…' })).toBeDisabled()
  finish(draft)
  await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/student/internships/7'))
  expect(await screen.findByText('Aplikimi u ruajt si draft.')).toBeInTheDocument()
  expect(saveInternship).toHaveBeenCalledTimes(1)
})

it('loads an existing draft, resets its fields, clears unrelated supervisor on company change and edits the same record', async () => {
  saveInternship.mockResolvedValue({ ...draft, company_id: 9, company_supervisor_id: null, company: { name: 'Other Company' }, supervisor: null })
  renderPage('/student/internships/7/edit')
  const title = await screen.findByLabelText('Titulli i pozitës *')
  expect(title).toHaveValue('Software Intern')
  await screen.findByRole('option', { name: 'Grace Mentor · Engineer' })
  fireEvent.change(title, { target: { value: 'Unsaved' } })
  fireEvent.click(screen.getByRole('button', { name: 'Rivendos fushat' }))
  expect(title).toHaveValue('Software Intern')
  fireEvent.change(screen.getByLabelText('Kompania *'), { target: { value: '9' } })
  expect(screen.getByLabelText('Mbikëqyrësi (opsional)')).toHaveValue('')
  await screen.findByRole('option', { name: 'Other Mentor' })
  expect(screen.queryByRole('option', { name: 'Grace Mentor · Engineer' })).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj ndryshimet' }))
  await waitFor(() => expect(saveInternship).toHaveBeenCalledWith(7, expect.objectContaining({ company_id: 9, company_supervisor_id: null })))
  expect(await screen.findByText('Drafti u përditësua me sukses.')).toBeInTheDocument()
})

it('preserves form values on backend validation failures and handles stale edits with fresh details', async () => {
  saveInternship.mockRejectedValueOnce({ status: 422, validationErrors: { position_title: ['Invalid position'] } }).mockRejectedValueOnce({ status: 409 })
  renderPage('/student/internships/7/edit')
  fireEvent.change(await screen.findByLabelText('Titulli i pozitës *'), { target: { value: 'My draft' } })
  await screen.findByRole('option', { name: 'Acme' })
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj ndryshimet' }))
  expect(await screen.findByText('Invalid position')).toBeInTheDocument()
  expect(screen.getByLabelText('Titulli i pozitës *')).toHaveValue('My draft')
  getInternship.mockResolvedValue({ ...draft, status: 'SUBMITTED' })
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj ndryshimet' }))
  expect(await screen.findByText(/Statusi i aplikimit ka ndryshuar/)).toBeInTheDocument()
  expect(await screen.findByText('Aplikim vetëm për lexim')).toBeInTheDocument()
  expect(screen.queryByRole('link', { name: 'Ndrysho draftin' })).not.toBeInTheDocument()
})

it('redirects direct edits of non-drafts to read-only details', async () => {
  getInternship.mockResolvedValue({ ...draft, status: 'APPROVED' })
  const memoryRouter = renderPage('/student/internships/7/edit')
  expect(await screen.findByText('Aplikim vetëm për lexim')).toBeInTheDocument()
  expect(memoryRouter.state.location.pathname).toBe('/student/internships/7')
  expect(screen.queryByLabelText('Titulli i pozitës *')).not.toBeInTheDocument()
})

it('shows loading and request errors and can retry the list', async () => {
  let reject
  listInternships.mockImplementationOnce(() => new Promise((resolve, fail) => { reject = fail }))
  renderPage()
  expect(await screen.findByRole('status')).toHaveTextContent('Duke ngarkuar aplikimet')
  await waitFor(() => expect(reject).toBeDefined())
  reject({ status: 500 })
  fireEvent.click(await screen.findByRole('button', { name: 'Provo përsëri' }))
  expect(await screen.findByRole('link', { name: 'Software Intern' })).toBeInTheDocument()
  expect(screen.queryByRole('alert')).not.toBeInTheDocument()
})

it('offers no placeholder company when lookups are empty and handles missing details', async () => {
  getInternshipCompanies.mockResolvedValue([])
  const memoryRouter = renderPage('/student/internships/new')
  expect(await screen.findByText(/Nuk ka kompani aktive të miratuara/)).toBeInTheDocument()
  expect(within(screen.getByLabelText('Kompania *')).getAllByRole('option')).toHaveLength(1)
  getInternship.mockRejectedValue({ status: 404 })
  await memoryRouter.navigate('/student/internships/999')
  expect(await screen.findByRole('alert')).toHaveTextContent('nuk u gjet')
})

it('guards internship routes from guests and non-students', async () => {
  const memoryRouter = renderPage('/student/internships', { ...student, role: 'COMPANY_SUPERVISOR' })
  await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/session'))
  expect(listInternships).not.toHaveBeenCalled()
})

it('redirects guests to login without requesting internship data', async () => {
  const memoryRouter = renderPage('/student/internships', null, false)
  await waitFor(() => expect(memoryRouter.state.location.pathname).toBe('/login'))
  expect(listInternships).not.toHaveBeenCalled()
})
