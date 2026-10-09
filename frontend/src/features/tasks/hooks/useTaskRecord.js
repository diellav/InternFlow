import { useEffect, useState } from 'react'
import { useAuth } from '../../auth/hooks/useAuth'
import { taskErrorMessage } from '../api/tasksApi'

export function useTaskRecord(loader, id) {
  const { refreshUser } = useAuth()
  const [data, setData] = useState(null)
  const [error, setError] = useState('')
  const [revision, setRevision] = useState(0)
  useEffect(() => {
    const controller = new AbortController()
    queueMicrotask(async () => {
      if (controller.signal.aborted) return
      setData(null); setError('')
      try { const result = await loader(id, controller.signal); if (!controller.signal.aborted) setData(result) }
      catch (failure) {
        if (controller.signal.aborted) return
        setError(taskErrorMessage(failure))
        if ([401, 403].includes(failure.status)) await refreshUser()
      }
    })
    return () => controller.abort()
  }, [loader, id, revision, refreshUser])
  return { data, error, reload: () => setRevision((value) => value + 1) }
}
