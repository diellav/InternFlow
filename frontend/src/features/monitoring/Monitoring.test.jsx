import { configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { downloadTaskFile } from '../tasks/api/tasksApi'
import { getMonitoringActivity, getMonitoringInternship, getMonitoringTask, listMonitoringActivities, listMonitoringInternships, listMonitoringSubmissions, listMonitoringTasks } from './api/monitoringApi'

vi.mock('./api/monitoringApi', async (original) => ({ ...await original(), getMonitoringActivity: vi.fn(), getMonitoringInternship: vi.fn(), getMonitoringTask: vi.fn(), listMonitoringActivities: vi.fn(), listMonitoringInternships: vi.fn(), listMonitoringSubmissions: vi.fn(), listMonitoringTasks: vi.fn() }))
vi.mock('../tasks/api/tasksApi', async (original) => ({ ...await original(), downloadTaskFile: vi.fn() }))
const user = { id: 9, first_name: 'Grace', last_name: 'Coordinator', role: 'ACADEMIC_COORDINATOR', is_active: true }
const parent = { id: 7, position_title: 'Academic monitoring', description: 'Internship work', status: 'ACTIVE', start_date: '2026-10-01', end_date: '2026-10-31', tasks_count: 1, approved_tasks_count: 1, student: { first_name: 'Arta', last_name: 'Student', study_program: 'CS' }, company: { name: 'Company' }, supervisor: { first_name: 'Drita', last_name: 'Mentor' } }
const task = { id: 3, title: 'Reviewed task', description: 'Build the form', priority: 'MEDIUM', status: 'APPROVED', due_date: null, progress_percent: null, assigned_by: { first_name: 'Drita', last_name: 'Mentor' }, internship: parent, can_edit: true, can_review: true, can_submit: true }
const activity = { id: 4, title: 'Diary 100%', description: 'Actual work', activity_date: '2026-10-02', hours: '2.50', internship: parent, can_edit: true }
const meta = { total: 1, current_page: 1, last_page: 1 }
const file = { id: 21, original_name: 'Report.pdf', mime_type: 'application/pdf', size_bytes: 1024 }
const submissions = [{ id: 2, version_no: 2, submission_text: 'Corrected work', submitted_at: '2026-10-08T12:00:00Z', resource_url: 'https://example.com/work', feedback: { decision: 'APPROVED', comment: 'Verified work', created_at: '2026-10-09T12:00:00Z' }, files: [file] }, { id: 1, version_no: 1, submission_text: 'First version', submitted_at: '2026-10-07T12:00:00Z', resource_url: 'javascript:alert(1)', feedback: { decision: 'REVISION_REQUIRED', comment: 'Add tests', created_at: '2026-10-08T12:00:00Z' }, files: [] }]
const routers = []
const refreshUser = vi.fn()
function page(path = '/coordinator/monitoring/internships', actor = user) {
  const memory = createMemoryRouter(router.routes, { initialEntries: [path] }); routers.push(memory)
  render(<AuthContext.Provider value={{ user: actor, isAuthenticated: Boolean(actor), isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>)
  return memory
}
beforeEach(() => {
  configure({ asyncUtilTimeout: 5000 }); vi.resetAllMocks()
  listMonitoringInternships.mockResolvedValue({ data: [parent], meta })
  getMonitoringInternship.mockResolvedValue(parent)
  listMonitoringTasks.mockResolvedValue({ data: [task], meta })
  getMonitoringTask.mockResolvedValue(task)
  getMonitoringActivity.mockResolvedValue(activity)
  listMonitoringSubmissions.mockResolvedValue({ data: submissions, meta: { ...meta, total: 2 } })
  listMonitoringActivities.mockResolvedValue({ data: [activity], meta, internship: parent, total_recorded_hours: '7.50', filtered_recorded_hours: '2.50' })
})
afterEach(() => { routers.splice(0).forEach((memory) => memory.dispose()); configure({ asyncUtilTimeout: 1000 }) })

it('adds monitoring navigation without replacing the review inbox and shows assigned internship information', async () => {
  page()
  expect(screen.getByRole('link', { name: 'Aplikimet për praktikë', exact: true })).toHaveAttribute('href', '/coordinator/internships')
  expect(screen.getByRole('link', { name: 'Monitorimi i praktikave', exact: true })).toHaveAttribute('href', '/coordinator/monitoring/internships')
  fireEvent.click(await screen.findByRole('link', { name: 'Academic monitoring' }))
  await screen.findByRole('heading', { name: 'Academic monitoring' })
  expect(screen.getByText('Arta Student')).toBeInTheDocument()
  expect(screen.getByText('Drita Mentor')).toBeInTheDocument()
  expect(await screen.findByRole('link', { name: 'Reviewed task' })).toHaveAttribute('href', '/coordinator/monitoring/tasks/3')
  expect(screen.queryByRole('button', { name: /Mirato|Refuzo|Kërko korrigjime|Fillo/ })).not.toBeInTheDocument()
})

it('paginates monitoring internships and preserves read-only links', async () => {
  listMonitoringInternships.mockImplementation(async (_, params) => ({ data: [parent], meta: { total: 16, current_page: params.page, last_page: 2 } }))
  page()
  await screen.findByRole('link', { name: 'Academic monitoring' })
  fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
  await waitFor(() => expect(listMonitoringInternships).toHaveBeenLastCalledWith('assigned', { page: 2, per_page: 15 }, expect.any(AbortSignal)))
  await screen.findByText('16 regjistra · Faqja 2 nga 2')
})

it('applies activity filters, preserves URL pagination and returns from details with context', async () => {
  const memory = page('/coordinator/monitoring/internships/7/activities?search=Diary&page=2')
  listMonitoringActivities.mockResolvedValue({ data: [activity], meta: { total: 17, current_page: 2, last_page: 2 }, internship: parent, total_recorded_hours: '7.50', filtered_recorded_hours: '2.50' })
  await screen.findByRole('link', { name: 'Diary 100%' })
  expect(listMonitoringActivities).toHaveBeenLastCalledWith('7', { page: 2, per_page: 15, search: 'Diary' }, expect.any(AbortSignal))
  expect(screen.getByText('7.50 orë', { selector: '.activity-hours-total' })).toBeInTheDocument()
  fireEvent.click(screen.getByRole('link', { name: 'Diary 100%' }))
  await screen.findByRole('heading', { name: 'Diary 100%' })
  expect(screen.queryByRole('link', { name: 'Ndrysho aktivitetin' })).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('link', { name: '← Aktivitetet dhe orët' }))
  await screen.findByRole('link', { name: 'Diary 100%' })
  expect(memory.state.location.search).toBe('?search=Diary&page=2')
  fireEvent.change(screen.getByLabelText('Data nga'), { target: { value: '2026-10-02' } })
  fireEvent.change(screen.getByLabelText('Kërko në titull ose përshkrim'), { target: { value: ' 100% ' } })
  listMonitoringActivities.mockResolvedValue({ data: [activity], meta, internship: parent, total_recorded_hours: '7.50', filtered_recorded_hours: '2.50' })
  fireEvent.click(screen.getByRole('button', { name: 'Apliko filtrat' }))
  await waitFor(() => expect(listMonitoringActivities).toHaveBeenLastCalledWith('7', { page: 1, per_page: 15, search: '100%', date_from: '2026-10-02' }, expect.any(AbortSignal)))
  await screen.findByText('2.50 orë', { selector: '.activity-hours-filtered' })
})

it('shows versioned history, supervisor feedback, safe HTTPS URLs and authenticated downloads without mutation controls', async () => {
  page('/coordinator/monitoring/tasks/3')
  await screen.findByRole('heading', { name: 'Dorëzimi 2' })
  expect(screen.getByRole('heading', { name: 'Dorëzimi 1' })).toBeInTheDocument()
  expect(screen.getByText('Verified work')).toBeInTheDocument()
  expect(screen.getByText('Add tests')).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'https://example.com/work' })).toHaveAttribute('rel', 'noopener noreferrer')
  expect(screen.queryByRole('link', { name: 'javascript:alert(1)' })).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Mirato|Dorëzo|Kërko korrigjime|Fillo|Anulo/ })).not.toBeInTheDocument()
  expect(screen.queryByRole('link', { name: /Ndrysho|Krijo|Regjistro/ })).not.toBeInTheDocument()
  downloadTaskFile.mockResolvedValue(undefined)
  fireEvent.click(screen.getByRole('link', { name: 'Report.pdf' }))
  await waitFor(() => expect(downloadTaskFile).toHaveBeenCalledWith(file))
})

it('paginates submission versions without enabling review controls', async () => {
  listMonitoringSubmissions.mockImplementation(async (_, params) => ({ data: [submissions[params.page === 1 ? 0 : 1]], meta: { total: 16, current_page: params.page, last_page: 2 } }))
  page('/coordinator/monitoring/tasks/3')
  await screen.findByRole('heading', { name: 'Dorëzimi 2' })
  fireEvent.click(screen.getByRole('button', { name: 'Pas', exact: true }))
  await screen.findByRole('heading', { name: 'Dorëzimi 1' })
  expect(screen.queryByRole('heading', { name: 'Dorëzimi 2' })).not.toBeInTheDocument()
  expect(listMonitoringSubmissions).toHaveBeenLastCalledWith('3', { page: 2, per_page: 15 }, expect.any(AbortSignal))
})

it('renders loading and empty internship, task and submission states', async () => {
  let finish
  listMonitoringInternships.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  const memory = page()
  await screen.findByText('Duke ngarkuar aktivitetet…')
  finish({ data: [], meta: { ...meta, total: 0 } })
  await screen.findByText('Nuk ka praktika për monitorim.')
  listMonitoringTasks.mockResolvedValue({ data: [], meta: { ...meta, total: 0 } })
  await memory.navigate('/coordinator/monitoring/internships/7')
  await screen.findByText('Nuk ka detyra të caktuara.')
  listMonitoringSubmissions.mockResolvedValue({ data: [], meta: { ...meta, total: 0 } })
  await memory.navigate('/coordinator/monitoring/tasks/3')
  await screen.findByText('Nuk ka dorëzime.')
})

it('handles foreign resources without stale data and permits retry', async () => {
  getMonitoringInternship.mockRejectedValueOnce({ status: 404 }).mockResolvedValue(parent)
  page('/coordinator/monitoring/internships/7')
  await screen.findByText('Praktika ose regjistri nuk është i disponueshëm në monitorimin tuaj.')
  expect(screen.queryByRole('heading', { name: 'Academic monitoring' })).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  await screen.findByRole('heading', { name: 'Academic monitoring' })
})

it('discards a late foreign internship request when navigating to a different internship', async () => {
  let finish
  getMonitoringInternship.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve })).mockResolvedValue({ ...parent, id: 8, position_title: 'Second monitoring' })
  const memory = page('/coordinator/monitoring/internships/7')
  await waitFor(() => expect(getMonitoringInternship).toHaveBeenCalledTimes(1))
  const signal = getMonitoringInternship.mock.calls[0][1]
  await memory.navigate('/coordinator/monitoring/internships/8')
  await screen.findByRole('heading', { name: 'Second monitoring' })
  expect(signal.aborted).toBe(true)
  finish(parent)
  await waitFor(() => expect(screen.queryByRole('heading', { name: 'Academic monitoring' })).not.toBeInTheDocument())
})

it('does not reveal monitoring data when activity filtering fails and recovers on retry', async () => {
  listMonitoringActivities.mockRejectedValueOnce({ status: 422 }).mockResolvedValue({ data: [], meta: { ...meta, total: 0 }, internship: parent, total_recorded_hours: '7.50', filtered_recorded_hours: '0.00' })
  page('/coordinator/monitoring/internships/7/activities?search=absent')
  await screen.findByText('Kontrolloni fushat dhe provoni përsëri.')
  expect(screen.queryByText('7.50 orë')).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  await screen.findByRole('heading', { name: 'Nuk ka aktivitete që përputhen' })
  expect(screen.getByText('0.00 orë', { selector: '.activity-hours-filtered' })).toBeInTheDocument()
})

it('shows private download errors without exposing storage paths or enabling actions', async () => {
  downloadTaskFile.mockRejectedValue({ status: 404 })
  page('/coordinator/monitoring/tasks/3')
  fireEvent.click(await screen.findByRole('link', { name: 'Report.pdf' }))
  await screen.findByRole('alert')
  expect(within(screen.getByRole('region', { name: 'Dokumentet e dorëzimit' })).getByRole('link', { name: 'Report.pdf' })).toHaveAttribute('href', expect.stringContaining('/api/task-submission-files/21/download'))
})

it('uses read-only wording for an empty coordinator activity diary', async () => {
  listMonitoringActivities.mockResolvedValue({ data: [], meta: { ...meta, total: 0 }, internship: parent, total_recorded_hours: '0.00', filtered_recorded_hours: '0.00' })
  page('/coordinator/monitoring/internships/7/activities')
  await screen.findByRole('heading', { name: 'Nuk ka aktivitete' })
  expect(screen.getByText('Studenti nuk ka regjistruar aktivitete për këtë praktikë.')).toBeInTheDocument()
  expect(screen.queryByText('Regjistroni aktivitetin tuaj të parë për këtë praktikë.')).not.toBeInTheDocument()
})
