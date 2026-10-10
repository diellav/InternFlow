import { configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getInternship, listInternships } from '../internships/api/internshipsApi'
import { getActivity, getActivityInternship, listActivities } from '../activities/api/activitiesApi'
import { downloadTaskFile, getStudentTask, listTasks, listTaskSubmissions } from '../tasks/api/tasksApi'
import { getStudentEvaluation } from './api/studentEvaluationApi'
import { evaluationLabels } from './constants/evaluationLabels'

vi.mock('./api/studentEvaluationApi', async (original) => ({ ...await original(), getStudentEvaluation: vi.fn() }))
vi.mock('../internships/api/internshipsApi', async (original) => ({ ...await original(), getInternship: vi.fn(), listInternships: vi.fn() }))
vi.mock('../activities/api/activitiesApi', async (original) => ({ ...await original(), getActivity: vi.fn(), getActivityInternship: vi.fn(), listActivities: vi.fn() }))
vi.mock('../tasks/api/tasksApi', async (original) => ({ ...await original(), getStudentTask: vi.fn(), listTasks: vi.fn(), listTaskSubmissions: vi.fn(), downloadTaskFile: vi.fn() }))
const student = { id: 8, first_name: 'Arta', last_name: 'Student', role: 'STUDENT', is_active: true }
const internship = { id: 7, position_title: 'Completed student internship', status: 'COMPLETED', completed_at: '2026-10-09T14:00:00Z', start_date: '2026-10-01', end_date: '2026-10-09', company: { name: 'Company' }, supervisor: { first_name: 'Drita', last_name: 'Mentor' } }
const evaluation = { id: 12, ...Object.fromEntries(Object.keys(evaluationLabels).map(field => [field, 4])), teamwork: 3, overall_score: '4.50', comments: 'Immutable supervisor assessment.\nLong final comments are retained.', submitted_at: '2026-10-09T12:00:00Z', evaluator: { first_name: 'Drita', last_name: 'Mentor' } }
const meta = { total: 1, current_page: 1, last_page: 1 }
const task = { id: 3, title: 'Reviewed evidence', status: 'APPROVED', priority: 'MEDIUM', internship, has_submissions: true, can_start: false, can_submit: false, can_resubmit: false, can_review: false, created_at: '2026-10-01T12:00:00Z', updated_at: '2026-10-09T12:00:00Z' }
const activity = { id: 2, title: 'Recorded work', description: 'Activity evidence', activity_date: '2026-10-08', hours: '0.50', created_at: '2026-10-08T12:00:00Z', internship, can_edit: false }
const files = [1, 2].map(id => ({ id, original_name: `Report_version_${id}.pdf`, size_bytes: 610, mime_type: 'application/pdf' }))
const submissions = [2, 1].map(version => ({ id: version, version_no: version, submission_text: `Work version ${version}`, submitted_at: '2026-10-08T12:00:00Z', files: [files[version - 1]], feedback: { decision: version === 2 ? 'APPROVED' : 'REVISION_REQUIRED', comment: `Feedback version ${version}`, created_at: '2026-10-09T12:00:00Z' } }))
const refreshUser = vi.fn()
const routers = []
function page(path = '/student/internships/7') {
  const memory = createMemoryRouter(router.routes, { initialEntries: [path] }); routers.push(memory)
  render(<AuthContext.Provider value={{ user: student, isAuthenticated: true, isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>)
  return memory
}
beforeEach(() => {
  configure({ asyncUtilTimeout: 5000 }); vi.resetAllMocks()
  getInternship.mockResolvedValue(internship)
  listInternships.mockResolvedValue({ data: [internship], meta })
  getStudentEvaluation.mockResolvedValue({ internship, evaluation })
  listTasks.mockResolvedValue({ data: [task], meta })
  getStudentTask.mockResolvedValue(task)
  listTaskSubmissions.mockResolvedValue({ data: submissions, meta: { ...meta, total: 2 } })
  getActivity.mockResolvedValue(activity)
  getActivityInternship.mockResolvedValue(internship)
  listActivities.mockResolvedValue({ data: [activity], meta, internship, total_recorded_hours: '1.75', filtered_recorded_hours: '0.50' })
})
afterEach(() => { routers.splice(0).forEach(memory => memory.dispose()); configure({ asyncUtilTimeout: 1000 }) })

it('shows completed date, all original criteria, decimal score and immutable comments without mutation controls', async () => {
  page()
  await screen.findByRole('heading', { name: 'Praktika e përfunduar' })
  await screen.findByText(/Immutable supervisor assessment/)
  const section = screen.getByRole('region', { name: 'Vlerësimi përfundimtar' })
  expect(section.querySelector('.evaluation-comments').textContent).toBe(evaluation.comments)
  for (const label of Object.values(evaluationLabels)) expect(within(section).getByText(label)).toBeInTheDocument()
  expect(within(section).getByText('4.50 / 5')).toBeInTheDocument()
  expect(within(section).getByText('3 / 5')).toBeInTheDocument()
  expect(document.querySelector(`time[datetime="${internship.completed_at}"]`)).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Shiko aktivitetet dhe orët' })).toHaveAttribute('href', '/student/internships/7/activities')
  expect(screen.queryByRole('button', { name: /Konfirmo|Dorëzo/ })).not.toBeInTheDocument()
  expect(screen.queryByRole('link', { name: /Ndrysho|Regjistro aktivitet/ })).not.toBeInTheDocument()
})

it('never requests or renders submitted evaluations while the internship is ACTIVE', async () => {
  getInternship.mockResolvedValue({ ...internship, status: 'ACTIVE', completed_at: null })
  page()
  await screen.findByText(/Vlerësimi përfundimtar bëhet i disponueshëm/)
  expect(getStudentEvaluation).not.toHaveBeenCalled()
  expect(screen.queryByText(/Immutable supervisor assessment/)).not.toBeInTheDocument()
})

it.each([
  { internship, evaluation: { ...evaluation, submitted_at: null } },
  { internship: { ...internship, status: 'ACTIVE' }, evaluation },
  { internship: { ...internship, completed_at: null }, evaluation },
  { internship: { ...internship, id: 9 }, evaluation },
])('defensively hides premature/draft/foreign evaluation fields', async (result) => {
  getStudentEvaluation.mockResolvedValue(result)
  page()
  await screen.findByText('Vlerësimi përfundimtar nuk është ende i disponueshëm për ju.')
  expect(screen.queryByText(/Immutable supervisor assessment/)).not.toBeInTheDocument()
})

it('handles evaluation loading, unavailable response and retry without exposing premature results', async () => {
  let fail
  getStudentEvaluation.mockImplementationOnce(() => new Promise((resolve, reject) => { fail = reject })).mockResolvedValue({ internship, evaluation })
  page()
  await screen.findByText('Duke ngarkuar vlerësimin…')
  await waitFor(() => expect(fail).toBeTypeOf('function'))
  fail({ status: 404 })
  await screen.findByRole('alert')
  expect(screen.queryByText(/Immutable supervisor assessment/)).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  await screen.findByText(/Immutable supervisor assessment/)
})

it('lists completed internships and navigates to the existing details/evidence interface', async () => {
  page('/student/internships')
  fireEvent.click(await screen.findByRole('link', { name: internship.position_title }))
  await screen.findByRole('heading', { name: 'Praktika e përfunduar' })
  await screen.findByText(/Immutable supervisor assessment/)
})

it('preserves completed activity filters, hours and filtered back navigation without create/edit controls', async () => {
  const memory = page('/student/internships/7/activities?date_from=2026-10-08&search=work')
  await screen.findByRole('link', { name: 'Recorded work' })
  expect(screen.getByRole('heading', { name: 'Orët totale të regjistruara' }).parentElement).toHaveTextContent('1.75')
  expect(screen.getByRole('heading', { name: 'Orët për periudhën e filtruar' }).parentElement).toHaveTextContent('0.50')
  expect(listActivities).toHaveBeenCalledWith('7', expect.objectContaining({ date_from: '2026-10-08', search: 'work' }), expect.any(AbortSignal))
  fireEvent.click(screen.getByRole('link', { name: 'Recorded work' }))
  await screen.findByText('Activity evidence')
  expect(screen.queryByRole('link', { name: 'Ndrysho aktivitetin' })).not.toBeInTheDocument()
  expect(screen.getByRole('link', { name: '← Aktivitetet e praktikës' })).toHaveAttribute('href', '/student/internships/7/activities?date_from=2026-10-08&search=work')
  fireEvent.click(screen.getByRole('link', { name: '← Aktivitetet e praktikës' }))
  await screen.findByRole('link', { name: 'Recorded work' })
  expect(memory.state.location.search).toContain('search=work')
  expect(screen.queryByRole('link', { name: 'Regjistro aktivitet' })).not.toBeInTheDocument()
})

it('uses completed history as the direct activity-details back destination and hides stale edit flags', async () => {
  getActivity.mockResolvedValue({ ...activity, can_edit: true })
  page('/student/activities/2')
  await screen.findByText('Activity evidence')
  expect(screen.getByRole('link', { name: '← Aktivitetet e praktikës' })).toHaveAttribute('href', '/student/internships/7/activities')
  expect(screen.queryByRole('link', { name: 'Ndrysho aktivitetin' })).not.toBeInTheDocument()
})

it.each(['/student/activities/2/edit', '/student/internships/7/activities/new'])('blocks direct completed activity form navigation %s', async (path) => {
  page(path)
  await screen.findByRole('alert')
  expect(screen.queryByRole('button', { name: /Ruaj|Regjistro/ })).not.toBeInTheDocument()
  expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
})

it('shows completed task versions and feedback and downloads the selected version file only', async () => {
  page('/student/tasks/3')
  await screen.findByText('Feedback version 2')
  expect(screen.getByText('Feedback version 1')).toBeInTheDocument()
  fireEvent.click(screen.getByRole('link', { name: 'Report_version_1.pdf' }))
  await waitFor(() => expect(downloadTaskFile).toHaveBeenCalledExactlyOnceWith(files[0]))
  expect(screen.queryByRole('button', { name: /Fillo|Dorëzo|Ridorëzo|Mirato/ })).not.toBeInTheDocument()
  expect(screen.getByRole('link', { name: '← Detajet e praktikës' })).toHaveAttribute('href', '/student/internships/7')
})

it('ignores stale submission/resubmission flags on a completed internship', async () => {
  getStudentTask.mockResolvedValue({ ...task, status: 'REVISION_REQUIRED', can_start: true, can_submit: true, can_resubmit: true })
  listTaskSubmissions.mockResolvedValue({ data: [submissions[1]], meta })
  page('/student/tasks/3')
  await screen.findByText('Feedback version 1')
  expect(screen.queryByRole('button', { name: /Fillo|Dorëzo|Ridorëzo/ })).not.toBeInTheDocument()
  expect(screen.queryByRole('textbox')).not.toBeInTheDocument()
})

it('handles empty completed activities without instructing the student to create new records', async () => {
  listActivities.mockResolvedValue({ data: [], meta: { ...meta, total: 0 }, internship, total_recorded_hours: '0.00', filtered_recorded_hours: '0.00' })
  page('/student/internships/7/activities')
  await screen.findByRole('heading', { name: 'Nuk ka aktivitete' })
  expect(screen.queryByText('Regjistroni aktivitetin tuaj të parë për këtë praktikë.')).not.toBeInTheDocument()
  expect(screen.queryByRole('link', { name: /Regjistro/ })).not.toBeInTheDocument()
})

it('renders empty tasks alongside the completed evaluation', async () => {
  listTasks.mockResolvedValue({ data: [], meta: { ...meta, total: 0 } })
  page()
  await screen.findByRole('heading', { name: 'Nuk ka detyra për t’u shfaqur' })
  await screen.findByText(/Immutable supervisor assessment/)
})

it('handles foreign internship failure without fetching evaluation or evidence', async () => {
  getInternship.mockRejectedValue({ status: 404 })
  page()
  await screen.findByRole('alert')
  expect(getStudentEvaluation).not.toHaveBeenCalled()
  expect(listTasks).not.toHaveBeenCalled()
})

it.each([401, 403, 500])('handles evaluation API error %s without exposing stored assessment fields', async (status) => {
  getStudentEvaluation.mockRejectedValue({ status })
  page()
  await screen.findByRole('alert')
  expect(screen.queryByText(/Immutable supervisor assessment/)).not.toBeInTheDocument()
  if ([401, 403].includes(status)) expect(refreshUser).toHaveBeenCalled()
})

it('does not invent scores for nullable legacy evaluation fields', async () => {
  getStudentEvaluation.mockResolvedValue({ internship, evaluation: { ...evaluation, overall_score: null, quality_of_work: null, initiative: null } })
  page()
  await screen.findByText(/Immutable supervisor assessment/)
  expect(screen.getAllByText('Nuk është shënuar')).toHaveLength(3)
  expect(screen.queryByText('4.50 / 5')).not.toBeInTheDocument()
})
