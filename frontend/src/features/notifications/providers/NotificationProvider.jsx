import { useCallback, useEffect, useRef, useState } from 'react'
import { getUnreadNotificationCount, markAllNotificationsAsRead, markNotificationAsRead, notificationErrorMessage } from '../api/notificationsApi'
import { NotificationContext } from '../context/NotificationContext'

export function NotificationProvider({ children }) {
  const [count, setCount] = useState(null)
  const [countError, setCountError] = useState('')
  const [mutationError, setMutationError] = useState('')
  const [busy, setBusy] = useState(false)
  const [revision, setRevision] = useState(0)
  const alive = useRef(false)
  const pending = useRef(false)
  const countRequest = useRef(0)
  const controller = useRef(null)

  const refreshCount = useCallback(async (force = false) => {
    if (!alive.current || (pending.current && force !== true)) return
    const request = ++countRequest.current
    controller.current?.abort()
    const abort = new AbortController()
    controller.current = abort
    try {
      const value = await getUnreadNotificationCount(abort.signal)
      if (alive.current && request === countRequest.current) {
        setCount(Number.isSafeInteger(value) && value >= 0 ? value : 0)
        setCountError('')
      }
    } catch (error) {
      if (alive.current && request === countRequest.current && !abort.signal.aborted) setCountError(notificationErrorMessage(error))
    }
  }, [])

  const stop = useCallback(() => {
    alive.current = false
    ++countRequest.current
    controller.current?.abort()
  }, [])

  useEffect(() => {
    let active = true
    alive.current = true
    queueMicrotask(() => { if (active) refreshCount() })
    const focus = () => { if (document.visibilityState === 'visible') refreshCount() }
    window.addEventListener('focus', focus)
    document.addEventListener('visibilitychange', focus)
    return () => {
      active = false
      stop()
      window.removeEventListener('focus', focus)
      document.removeEventListener('visibilitychange', focus)
    }
  }, [refreshCount, stop])

  async function mutate(id) {
    if (pending.current || !alive.current) return false
    pending.current = true
    ++countRequest.current
    controller.current?.abort()
    setBusy(true)
    setMutationError('')
    try {
      if (id) await markNotificationAsRead(id)
      else await markAllNotificationsAsRead()
      await refreshCount(true)
      return alive.current
    } catch (error) {
      if (alive.current) setMutationError(notificationErrorMessage(error))
      return false
    } finally {
      pending.current = false
      if (alive.current) { setBusy(false); setRevision(value => value + 1) }
    }
  }

  return <NotificationContext.Provider value={{ count, countError, mutationError, busy, revision, refreshCount, markRead: id => mutate(id), markAll: () => mutate() }}>{children}</NotificationContext.Provider>
}
