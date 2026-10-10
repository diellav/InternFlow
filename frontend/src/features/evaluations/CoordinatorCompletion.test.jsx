import { configure, fireEvent, render, screen, waitFor, within } from '@testing-library/react'
import { createMemoryRouter, RouterProvider } from 'react-router-dom'
import { afterEach, beforeEach, expect, it, vi } from 'vitest'
import { router } from '../../app/router/router'
import { AuthContext } from '../auth/context/AuthContext'
import { getMonitoringInternship, listMonitoringTasks } from '../monitoring/api/monitoringApi'
import { completeInternship, getCoordinatorEvaluation } from './api/coordinatorEvaluationApi'
import { evaluationLabels } from './constants/evaluationLabels'

vi.mock('./api/coordinatorEvaluationApi', async (original) => ({ ...await original(), getCoordinatorEvaluation: vi.fn(), completeInternship: vi.fn() }))
vi.mock('../monitoring/api/monitoringApi', async (original) => ({ ...await original(), getMonitoringInternship: vi.fn(), listMonitoringTasks: vi.fn() }))
const actor = { id: 9, first_name: 'Grace', last_name: 'Coordinator', role: 'ACADEMIC_COORDINATOR', is_active: true }
const internship = { id: 7, position_title: 'Completion internship', description: 'Internship evidence', status: 'ACTIVE', completed_at: null, start_date: '2026-10-01', end_date: '2026-10-09', tasks_count: 1, approved_tasks_count: 1, student: { first_name: 'Arta', last_name: 'Student', study_program: 'CS' }, company: { name: 'Company' }, supervisor: { first_name: 'Drita', last_name: 'Mentor' } }
const evaluation = { id: 12, ...Object.fromEntries(Object.keys(evaluationLabels).map((field) => [field, 4])), overall_score: '4.50', teamwork: 3, comments: 'Professional work and technical progress throughout the internship.', submitted_at: '2026-10-09T12:00:00Z', evaluator: { first_name: 'Drita', last_name: 'Mentor' } }
const ready = { internship, evaluation, can_complete: true, completion_blocker: null }
const completed = { ...internship, status: 'COMPLETED', completed_at: '2026-10-09T14:00:00Z' }
const refreshUser = vi.fn()
const routers = []
function page(id = 7) {
  const memory = createMemoryRouter(router.routes, { initialEntries: [`/coordinator/monitoring/internships/${id}`] }); routers.push(memory)
  render(<AuthContext.Provider value={{ user: actor, isAuthenticated: true, isLoading: false, refreshUser, logout: vi.fn() }}><RouterProvider router={memory} /></AuthContext.Provider>)
  return memory
}
beforeEach(() => {
  configure({ asyncUtilTimeout: 5000 }); vi.resetAllMocks()
  getMonitoringInternship.mockResolvedValue(internship)
  listMonitoringTasks.mockResolvedValue({ data: [], meta: { total: 0, current_page: 1, last_page: 1 } })
  getCoordinatorEvaluation.mockResolvedValue(ready)
  completeInternship.mockResolvedValue(completed)
  HTMLDialogElement.prototype.showModal = function () { this.setAttribute('open', '') }
  HTMLDialogElement.prototype.close = function () { this.removeAttribute('open') }
})
afterEach(() => { routers.splice(0).forEach((memory) => memory.dispose()); configure({ asyncUtilTimeout: 1000 }) })

it('shows submitted supervisor criteria including teamwork and decimal score without accepting on read', async () => {
  page()
  await screen.findByText(evaluation.comments)
  const section = screen.getByRole('region', { name: 'Vlerësimi përfundimtar' })
  for (const label of Object.values(evaluationLabels)) expect(within(section).getByText(label)).toBeInTheDocument()
  expect(within(section).getByText('4.50 / 5')).toBeInTheDocument()
  expect(within(section).getByText('3 / 5')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Konfirmo përfundimin e praktikës' })).toBeEnabled()
  expect(completeInternship).not.toHaveBeenCalled()
  expect(screen.queryByRole('combobox')).not.toBeInTheDocument()
})

it('renders pending state without showing a draft or completion action', async () => {
  getCoordinatorEvaluation.mockResolvedValue({ ...ready, evaluation: null, can_complete: false, completion_blocker: 'MISSING_SUBMITTED_EVALUATION' })
  page()
  await screen.findByText('Në pritje të vlerësimit përfundimtar të dorëzuar nga mbikëqyrësi.')
  expect(screen.queryByRole('button', { name: 'Konfirmo përfundimin e praktikës' })).not.toBeInTheDocument()
  expect(screen.queryByText(evaluation.comments)).not.toBeInTheDocument()
})

it('does not render a draft even when unexpected draft fields or action flags arrive', async () => {
  getCoordinatorEvaluation.mockResolvedValue({ ...ready, evaluation: { ...evaluation, submitted_at: null, comments: 'Private draft content' } })
  page()
  await screen.findByText('Në pritje të vlerësimit përfundimtar të dorëzuar nga mbikëqyrësi.')
  expect(screen.queryByText('Private draft content')).not.toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Konfirmo përfundimin e praktikës' })).not.toBeInTheDocument()
})

it.each([
  ['NOT_ACTIVE', 'Përfundimi mund të konfirmohet vetëm për një praktikë aktive.'],
  ['END_DATE_NOT_REACHED', 'Data e përfundimit të praktikës ende nuk është arritur.'],
  ['UNRESOLVED_TASKS', 'Ka dorëzime pa shqyrtim ose detyra të dorëzuara/që kërkojnë korrigjime. Mbikëqyrësi duhet t’i zgjidhë përpara përfundimit.'],
])('explains readiness blocker %s without enabling completion', async (completion_blocker, message) => {
  getCoordinatorEvaluation.mockResolvedValue({ ...ready, can_complete: false, completion_blocker })
  page()
  await screen.findByText(message)
  expect(screen.queryByRole('button', { name: 'Konfirmo përfundimin e praktikës' })).not.toBeInTheDocument()
  expect(screen.getByText(evaluation.comments)).toBeInTheDocument()
})

it('requires a confirmation dialog and permits cancellation without mutation', async () => {
  page()
  fireEvent.click(await screen.findByRole('button', { name: 'Konfirmo përfundimin e praktikës' }))
  const dialog = screen.getByRole('dialog', { name: 'Konfirmoni përfundimin e praktikës?' })
  expect(dialog).toHaveAccessibleDescription(/ACTIVE në COMPLETED/)
  fireEvent.click(within(dialog).getByRole('button', { name: 'Anulo' }))
  expect(completeInternship).not.toHaveBeenCalled()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('completes once, shows server completed_at and preserves monitoring links and evaluation', async () => {
  let finish
  completeInternship.mockImplementation(() => new Promise((resolve) => { finish = resolve }))
  page()
  fireEvent.click(await screen.findByRole('button', { name: 'Konfirmo përfundimin e praktikës' }))
  getMonitoringInternship.mockResolvedValue(completed)
  getCoordinatorEvaluation.mockResolvedValue({ ...ready, internship: completed, can_complete: false, completion_blocker: 'COMPLETED' })
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo përfundimin', exact: true }))
  fireEvent.click(screen.getByRole('button', { name: 'Duke konfirmuar…' }))
  expect(completeInternship).toHaveBeenCalledExactlyOnceWith(7, 12)
  expect(screen.getByRole('button', { name: 'Duke konfirmuar…' })).toBeDisabled()
  finish(completed)
  await screen.findByText('COMPLETED · Praktika e përfunduar')
  expect(screen.getByText('COMPLETED', { exact: true })).toBeInTheDocument()
  expect(document.querySelector('time')).toHaveAttribute('datetime', completed.completed_at)
  expect(screen.queryByRole('button', { name: 'Konfirmo përfundimin e praktikës' })).not.toBeInTheDocument()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.getByText(evaluation.comments)).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Shiko aktivitetet dhe orët' })).toHaveAttribute('href', '/coordinator/monitoring/internships/7/activities')
})

it('renders completed state and date on later visits without another completion action', async () => {
  getMonitoringInternship.mockResolvedValue(completed)
  getCoordinatorEvaluation.mockResolvedValue({ ...ready, internship: completed, can_complete: false, completion_blocker: 'COMPLETED' })
  page()
  await screen.findByText('COMPLETED · Praktika e përfunduar')
  expect(document.querySelector('time')).toHaveAttribute('datetime', completed.completed_at)
  expect(screen.queryByRole('button', { name: /Konfirmo përfundimin/ })).not.toBeInTheDocument()
})

it('handles loading and retry for evaluation read without exposing a foreign record', async () => {
  let fail
  getCoordinatorEvaluation.mockImplementationOnce(() => new Promise((resolve, reject) => { fail = reject })).mockResolvedValue(ready)
  page()
  await screen.findByText('Duke ngarkuar vlerësimin…')
  await waitFor(() => expect(fail).toBeTypeOf('function'))
  fail({ status: 404 })
  await screen.findByRole('alert')
  expect(screen.queryByText(evaluation.comments)).not.toBeInTheDocument()
  fireEvent.click(screen.getByRole('button', { name: 'Provo përsëri' }))
  await screen.findByText(evaluation.comments)
})

it('preserves a submitted evaluation and allows retry after completion network failure', async () => {
  completeInternship.mockRejectedValueOnce({ status: null })
  page()
  fireEvent.click(await screen.findByRole('button', { name: 'Konfirmo përfundimin e praktikës' }))
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo përfundimin', exact: true }))
  await screen.findByRole('alert')
  expect(screen.getByText(evaluation.comments)).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Konfirmo përfundimin e praktikës' })).toBeEnabled()
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
})

it('refreshes stale completion state and retains the conflict message across the parent reload', async () => {
  completeInternship.mockRejectedValue({ status: 409 })
  page()
  fireEvent.click(await screen.findByRole('button', { name: 'Konfirmo përfundimin e praktikës' }))
  getMonitoringInternship.mockResolvedValue(completed)
  getCoordinatorEvaluation.mockResolvedValue({ ...ready, internship: completed, can_complete: false, completion_blocker: 'COMPLETED' })
  fireEvent.click(screen.getByRole('button', { name: 'Konfirmo përfundimin', exact: true }))
  await screen.findByText('COMPLETED · Praktika e përfunduar')
  expect(screen.getByRole('alert')).toHaveTextContent('Gjendja ose vlerësimi ka ndryshuar')
  expect(screen.queryByRole('button', { name: 'Konfirmo përfundimin e praktikës' })).not.toBeInTheDocument()
})

it('clears open completion confirmation and stale evaluation when changing internship', async () => {
  const memory = page()
  fireEvent.click(await screen.findByRole('button', { name: 'Konfirmo përfundimin e praktikës' }))
  getMonitoringInternship.mockResolvedValue({ ...internship, id: 8, position_title: 'Second internship' })
  getCoordinatorEvaluation.mockResolvedValue({ ...ready, internship: { ...internship, id: 8 }, evaluation: null, can_complete: false, completion_blocker: 'MISSING_SUBMITTED_EVALUATION' })
  await memory.navigate('/coordinator/monitoring/internships/8')
  await screen.findByText('Second internship')
  await screen.findByText('Në pritje të vlerësimit përfundimtar të dorëzuar nga mbikëqyrësi.')
  expect(screen.queryByRole('dialog')).not.toBeInTheDocument()
  expect(screen.queryByText(evaluation.comments)).not.toBeInTheDocument()
})

it('retains review navigation and readonly task/activity evidence links', async () => {
  listMonitoringTasks.mockResolvedValue({ data: [{ id: 3, title: 'Evidence task', priority: 'MEDIUM', status: 'APPROVED' }], meta: { total: 1, current_page: 1, last_page: 1 } })
  page()
  await screen.findByText(evaluation.comments)
  expect(screen.getByRole('link', { name: 'Evidence task' })).toHaveAttribute('href', '/coordinator/monitoring/tasks/3')
  expect(screen.getByRole('link', { name: 'Aplikimet për praktikë', exact: true })).toHaveAttribute('href', '/coordinator/internships')
  expect(screen.queryByRole('button', { name: /Mirato|Refuzo|Kërko korrigjime/ })).not.toBeInTheDocument()
})
