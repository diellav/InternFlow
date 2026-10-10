import { beforeEach, expect, it, vi } from 'vitest'
import { apiClient } from '../../shared/api/apiClient'
import { initializeCsrf } from '../auth/api/authApi'
import { getEvaluation, saveEvaluation, submitEvaluation } from './api/evaluationsApi'

vi.mock('../../shared/api/apiClient', () => ({ apiClient: { get: vi.fn(), put: vi.fn(), post: vi.fn() } }))
vi.mock('../auth/api/authApi', () => ({ initializeCsrf: vi.fn() }))
beforeEach(() => vi.clearAllMocks())

it('fetches the scoped evaluation with its abort signal', async () => {
  const signal = new AbortController().signal
  apiClient.get.mockResolvedValue({ data: { data: { evaluation: null } } })
  expect(await getEvaluation(7, signal)).toEqual({ evaluation: null })
  expect(apiClient.get).toHaveBeenCalledWith('/api/supervisor/internships/7/final-evaluation', { signal })
  expect(initializeCsrf).not.toHaveBeenCalled()
})

it('initializes CSRF before PUT and submits only the saved draft token', async () => {
  apiClient.put.mockResolvedValue({ data: { data: { evaluation: { status: 'DRAFT' } } } })
  apiClient.post.mockResolvedValue({ data: { data: { evaluation: { status: 'SUBMITTED' } } } })
  await saveEvaluation(7, { technical_skills: 4 })
  expect(initializeCsrf.mock.invocationCallOrder[0]).toBeLessThan(apiClient.put.mock.invocationCallOrder[0])
  expect(apiClient.put).toHaveBeenCalledWith('/api/supervisor/internships/7/final-evaluation', { technical_skills: 4 })
  await submitEvaluation(7, 'token')
  expect(apiClient.post).toHaveBeenCalledWith('/api/supervisor/internships/7/final-evaluation/submit', { draft_token: 'token' })
})

it('normalizes API failures without exposing server internals', async () => {
  apiClient.get.mockRejectedValue({ isAxiosError: true, response: { status: 422, data: { errors: { comments: ['Required'] }, message: 'internal' } } })
  await expect(getEvaluation(7)).rejects.toEqual(expect.objectContaining({ status: 422, validationErrors: { comments: ['Required'] } }))
})
