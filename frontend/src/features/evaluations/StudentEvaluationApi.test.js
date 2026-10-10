import { beforeEach, expect, it, vi } from 'vitest'
import { apiClient } from '../../shared/api/apiClient'
import { getStudentEvaluation, studentEvaluationErrorMessage } from './api/studentEvaluationApi'

vi.mock('../../shared/api/apiClient', () => ({ apiClient: { get: vi.fn() } }))
beforeEach(() => vi.clearAllMocks())
it('performs only an authenticated evaluation read with abort support', async () => {
  const signal = new AbortController().signal
  apiClient.get.mockResolvedValue({ data: { data: { evaluation: { overall_score: '4.50' } } } })
  expect(await getStudentEvaluation(7, signal)).toEqual({ evaluation: { overall_score: '4.50' } })
  expect(apiClient.get).toHaveBeenCalledExactlyOnceWith('/api/student/internships/7/final-evaluation', { signal })
})
it.each([401, 403, 404, 500])('normalizes API status %s without rendering backend messages', async (status) => {
  apiClient.get.mockRejectedValue({ isAxiosError: true, response: { status, data: { message: 'Private internal message' } } })
  await expect(getStudentEvaluation(7)).rejects.toEqual(expect.objectContaining({ status }))
  expect(studentEvaluationErrorMessage({ status })).not.toContain('Private internal message')
})
