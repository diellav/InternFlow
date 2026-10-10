import { beforeEach, expect, it, vi } from 'vitest'
import { apiClient } from '../../shared/api/apiClient'
import { initializeCsrf } from '../auth/api/authApi'
import { completeInternship, getCoordinatorEvaluation } from './api/coordinatorEvaluationApi'

vi.mock('../../shared/api/apiClient', () => ({ apiClient: { get: vi.fn(), post: vi.fn() } }))
vi.mock('../auth/api/authApi', () => ({ initializeCsrf: vi.fn() }))
beforeEach(() => vi.clearAllMocks())

it('reads submitted evaluations from scoped monitoring with abort support', async () => {
  const signal = new AbortController().signal
  apiClient.get.mockResolvedValue({ data: { data: { evaluation: null } } })
  expect(await getCoordinatorEvaluation(7, signal)).toEqual({ evaluation: null })
  expect(apiClient.get).toHaveBeenCalledWith('/api/coordinator/monitoring/internships/7/final-evaluation', { signal })
  expect(initializeCsrf).not.toHaveBeenCalled()
})

it('initializes CSRF and submits only the expected evaluation ID, never status or completed_at', async () => {
  apiClient.post.mockResolvedValue({ data: { data: { status: 'COMPLETED' } } })
  expect(await completeInternship(7, 12)).toEqual({ status: 'COMPLETED' })
  expect(initializeCsrf.mock.invocationCallOrder[0]).toBeLessThan(apiClient.post.mock.invocationCallOrder[0])
  expect(apiClient.post).toHaveBeenCalledWith('/api/coordinator/internships/7/complete', { expected_evaluation_id: 12 })
})

it('normalizes foreign completion errors without exposing server internals', async () => {
  apiClient.post.mockRejectedValue({ isAxiosError: true, response: { status: 404, data: { message: 'Private internal data' } } })
  await expect(completeInternship(7, 12)).rejects.toEqual(expect.objectContaining({ status: 404 }))
})
