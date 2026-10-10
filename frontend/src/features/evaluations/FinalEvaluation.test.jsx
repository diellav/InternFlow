import { configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getSupervisorInternship, listTasks } from '../tasks/api/tasksApi'
import { evaluationLabels, submissionBlockers } from './constants/evaluationLabels'
import { getEvaluation, saveEvaluation, submitEvaluation } from './api/evaluationsApi'

vi.mock('./api/evaluationsApi', async (original) => ({ ...await original(), getEvaluation: vi.fn(), saveEvaluation: vi.fn(), submitEvaluation: vi.fn() }))
vi.mock('../tasks/api/tasksApi', async (original) => ({ ...await original(), getSupervisorInternship: vi.fn(), listTasks: vi.fn() }))
const supervisor = { id: 9, first_name: 'Drita', last_name: 'Mentor', role: 'COMPANY_SUPERVISOR', is_active: true }
const ratings = Object.fromEntries(Object.keys(evaluationLabels).map((field) => [field, 4]))
const comments = 'The student demonstrated professional skills and consistent technical progress.'
const empty = { internship: { id: 7, position_title: 'Evaluation internship', status: 'ACTIVE', end_date: '2026-10-09' }, server_date: '2026-10-09', can_edit: true, can_submit: false, submission_blocker: 'NO_DRAFT', evaluation: null }
const draft = { ...empty, can_submit: true, submission_blocker: null, evaluation: { id: 1, status: 'DRAFT', ...ratings, comments, submitted_at: null, draft_token: 'a'.repeat(64) } }
const submitted = { ...draft, can_edit: false, can_submit: false, submission_blocker: 'SUBMITTED', evaluation: { ...draft.evaluation, status: 'SUBMITTED', submitted_at: '2026-10-09T12:00:00Z', draft_token: null } }
const refreshUser = vi.fn()
const routers = []
function page(path = '/supervisor/internships/7/final-evaluation', actor = supervisor) {
  const memory = createMemoryRouter(router.routes, { initialEntries: [path] }); routers.push(memory)
  render(<AuthContext.Provider value={{ user: actor, isAuthenticated: Boolean(actor), isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>)
  return memory
}
beforeEach(() => {
  configure({ asyncUtilTimeout: 5000 }); vi.resetAllMocks()
  getEvaluation.mockResolvedValue(empty); saveEvaluation.mockResolvedValue(draft); submitEvaluation.mockResolvedValue(submitted)
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
  getSupervisorInternship.mockResolvedValue({ ...empty.internship, company: { name: 'Company' }, student: { first_name: 'Arta', last_name: 'Student', study_program: 'CS' }, can_create_tasks: true })
  listTasks.mockResolvedValue({ data: [], meta: { total: 0, current_page: 1, last_page: 1 } })
})
afterEach(() => { routers.splice(0).forEach((memory) => memory.dispose()); configure({ asyncUtilTimeout: 1000 }) })

it('renders the empty evaluation form with all Albanian criteria and scale', async () => {
  page()
  await screen.findByText('Nuk ka ende vlerësim.')
  for (const label of Object.values(evaluationLabels)) expect(screen.getByLabelText(`${label} *`)).toHaveValue('')
  expect(screen.getByRole('button', { name: 'Dorëzo vlerësimin' })).toBeDisabled()
  expect(screen.getByText(/Shkalla: 1/)).toBeInTheDocument()
  expect(screen.getByRole('link', { name: '← Detajet e praktikës' })).toHaveAttribute('href', '/supervisor/internships/7')
})

it('selects ratings, saves partial drafts, shows confirmation and prevents duplicate saves', async () => {
  let finish
  saveEvaluation.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  page()
  fireEvent.change(await screen.findByLabelText('Aftësitë teknike *'), { target: { value: '5' } })
  fireEvent.change(screen.getByLabelText('Komenti përfundimtar i mbikëqyrësit *'), { target: { value: 'Progress' } })
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj draftin' }))
  fireEvent.submit(screen.getByRole('form'))
  expect(saveEvaluation).toHaveBeenCalledTimes(1)
  expect(saveEvaluation).toHaveBeenCalledWith('7', expect.objectContaining({ technical_skills: 5, quality_of_work: null, comments: 'Progress' }))
  expect(screen.getByRole('button', { name: 'Duke ruajtur…' })).toBeDisabled()
  finish({ ...draft, evaluation: { ...draft.evaluation, technical_skills: 5 } })
  await screen.findByText('Drafti u ruajt me sukses.')
  expect(screen.getByLabelText('Aftësitë teknike *')).toHaveValue('5')
})

it('reloads persisted draft and requires saving edits before submission', async () => {
  getEvaluation.mockResolvedValue(draft)
  page()
  expect(await screen.findByLabelText('Cilësia e punës *')).toHaveValue('4')
  expect(screen.getByLabelText('Komenti përfundimtar i mbikëqyrësit *')).toHaveValue(comments)
  fireEvent.change(screen.getByLabelText('Cilësia e punës *'), { target: { value: '5' } })
  expect(screen.getByRole('button', { name: 'Dorëzo vlerësimin' })).toBeDisabled()
  saveEvaluation.mockResolvedValue({ ...draft, evaluation: { ...draft.evaluation, quality_of_work: 5, draft_token: 'b'.repeat(64) } })
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj draftin' }))
  await screen.findByText('Drafti u ruajt me sukses.')
  expect(screen.getByRole('button', { name: 'Dorëzo vlerësimin' })).toBeEnabled()
})

it('validates missing criteria and meaningful comments before opening confirmation', async () => {
  getEvaluation.mockResolvedValue({ ...draft, evaluation: { ...draft.evaluation, technical_skills: null, comments: 'Short' } })
  page()
  fireEvent.click(await screen.findByRole('button', { name: 'Dorëzo vlerësimin' }))
  expect(screen.getByText('Zgjidhni një vlerësim nga 1 deri në 5.')).toBeInTheDocument()
  expect(screen.getByText(/Shkruani një koment kuptimplotë/)).toBeInTheDocument()
  expect(submitEvaluation).not.toHaveBeenCalled()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('confirms submission once, becomes read-only and states internship remains active', async () => {
  let finish
  getEvaluation.mockResolvedValue(draft)
  submitEvaluation.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  page()
  fireEvent.click(await screen.findByRole('button', { name: 'Dorëzo vlerësimin' }))
  const dialog = screen.getByRole('dialog', { name: 'Dorëzoni vlerësimin përfundimtar?' })
  fireEvent.click(within(dialog).getByRole('button', { name: 'Konfirmo dorëzimin' }))
  fireEvent.click(within(dialog).getByRole('button', { name: 'Duke dorëzuar…' }))
  expect(submitEvaluation).toHaveBeenCalledExactlyOnceWith('7', 'a'.repeat(64))
  finish(submitted)
  await screen.findByText('Vlerësimi u dorëzua me sukses. Praktika mbetet aktive.')
  expect(screen.getByRole('heading', { name: 'Vlerësimi i dorëzuar' })).toBeInTheDocument()
  expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Ruaj draftin' })).not.toBeInTheDocument()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('cancels final confirmation without submission', async () => {
  getEvaluation.mockResolvedValue(draft); page()
  fireEvent.click(await screen.findByRole('button', { name: 'Dorëzo vlerësimin' }))
  fireEvent.click(within(screen.getByRole('dialog')).getByRole('button', { name: 'Anulo' }))
  expect(submitEvaluation).not.toHaveBeenCalled()
  expect(screen.getByRole('button', { name: 'Dorëzo vlerësimin' })).toBeEnabled()
})

it('renders submitted evaluations without any mutation controls', async () => {
  getEvaluation.mockResolvedValue({ ...submitted, can_edit: true }); page()
  await screen.findByText('I dorëzuar · vetëm lexim')
  expect(screen.getByText(comments)).toBeInTheDocument()
  expect(screen.queryByRole('form')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Ruaj|Dorëzo/ })).not.toBeInTheDocument()
})

it('shows a loading state and discards late requests for a previous internship', async () => {
  let finish
  getEvaluation.mockImplementationOnce(() => new Promise((resolve) => { finish = resolve })).mockResolvedValueOnce({ ...empty, internship: { ...empty.internship, id: 8, position_title: 'Current internship' } })
  const memory = page()
  await screen.findByText('Duke ngarkuar vlerësimin…')
  await waitFor(() => expect(finish).toBeTypeOf('function'))
  const signal = getEvaluation.mock.calls[0][1]
  await memory.navigate('/supervisor/internships/8/final-evaluation')
  await screen.findByText('Current internship')
  finish(draft)
  await waitFor(() => expect(screen.queryByText('Evaluation internship')).not.toBeInTheDocument())
  expect(signal.aborted).toBe(true)
})

it('retains the draft after failed submission and displays server rating validation', async () => {
  getEvaluation.mockResolvedValue(draft)
  submitEvaluation.mockRejectedValue({ status: 422, validationErrors: { overall_score: ['Select overall performance'] } })
  page()
  fireEvent.click(await screen.findByRole('button', { name: 'Dorëzo vlerësimin' }))
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo dorëzimin' }))
  await screen.findByText('Select overall performance')
  expect(screen.getByLabelText('Aftësitë teknike *')).toHaveValue('4')
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it.each(['NOT_ACTIVE', 'END_DATE_NOT_REACHED', 'UNRESOLVED_TASKS'])('explains submission blocker %s without inventing completion or hours requirements', async (blocker) => {
  getEvaluation.mockResolvedValue({ ...draft, can_edit: blocker !== 'NOT_ACTIVE', can_submit: false, submission_blocker: blocker }); page()
  await screen.findByText(submissionBlockers[blocker])
  if (blocker === 'NOT_ACTIVE') expect(screen.queryByRole('form')).not.toBeInTheDocument()
  else expect(screen.getByRole('button', { name: 'Ruaj draftin' })).toBeEnabled()
  expect(screen.queryByRole('button', { name: 'Dorëzo vlerësimin' })?.disabled ?? true).toBe(true)
})

it('shows loading, fetch error and retry without leaking a foreign record', async () => {
  getEvaluation.mockRejectedValueOnce({ status: 404 }).mockResolvedValue(draft); page()
  await screen.findByRole('alert')
  expect(screen.queryByText('Evaluation internship')).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  await screen.findByText('Draft · në përgatitje')
})

it('keeps form values on network and validation failures', async () => {
  getEvaluation.mockResolvedValue(draft)
  saveEvaluation.mockRejectedValueOnce({ status: null }).mockRejectedValueOnce({ status: 422, validationErrors: { comments: ['Server validation message'] } })
  page()
  fireEvent.click(await screen.findByRole('button', { name: 'Ruaj draftin' }))
  await screen.findByRole('alert')
  expect(screen.getByLabelText('Komenti përfundimtar i mbikëqyrësit *')).toHaveValue(comments)
  fireEvent.click(screen.getByRole('button', { name: 'Ruaj draftin' }))
  await screen.findByText('Server validation message')
})

it('reloads a stale submission instead of leaving editable stale state', async () => {
  getEvaluation.mockResolvedValueOnce(draft).mockResolvedValue(submitted); submitEvaluation.mockRejectedValue({ status: 409 }); page()
  fireEvent.click(await screen.findByRole('button', { name: 'Dorëzo vlerësimin' }))
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo dorëzimin' }))
  await screen.findByText('I dorëzuar · vetëm lexim')
  expect(screen.getByRole('alert')).toHaveTextContent('Gjendja ose drafti ka ndryshuar')
})

it('clears stale data and confirmation when navigating to another internship', async () => {
  getEvaluation.mockResolvedValueOnce(draft).mockResolvedValueOnce({ ...empty, internship: { ...empty.internship, id: 8, position_title: 'Other internship' } })
  const memory = page()
  fireEvent.click(await screen.findByRole('button', { name: 'Dorëzo vlerësimin' }))
  await memory.navigate('/supervisor/internships/8/final-evaluation')
  await screen.findByText('Other internship')
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.getByLabelText('Aftësitë teknike *')).toHaveValue('')
})

it('integrates with assigned internship navigation without removing task or activity links', async () => {
  page('/supervisor/internships/7')
  fireEvent.click(await screen.findByRole('link', { name: 'Hap vlerësimin përfundimtar' }))
  await screen.findByRole('heading', { name: 'Vlerësimi përfundimtar', exact: true })
  expect(screen.getByRole('link', { name: 'Profili', exact: true })).toBeInTheDocument()
  expect(getEvaluation).toHaveBeenCalledWith('7', expect.any(AbortSignal))
})

it('does not expose the evaluation page to student or coordinator routes', async () => {
  page('/supervisor/internships/7/final-evaluation', { ...supervisor, role: 'STUDENT' })
  await waitFor(() => expect(getEvaluation).not.toHaveBeenCalled())
  expect(screen.queryByRole('form')).not.toBeInTheDocument()
})
