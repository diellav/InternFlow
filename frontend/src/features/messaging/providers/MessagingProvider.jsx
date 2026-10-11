import { useCallback, useEffect, useRef, useState } from 'react'
import { useAuth } from '../../auth/hooks/useAuth'
import { useNotifications } from '../../notifications/context/NotificationContext'
import { getUnreadMessageCount } from '../api/messagingApi'
import { connectMessaging } from '../realtime/messagingRealtime'
import { MessagingContext } from '../context/MessagingContext'

export function MessagingProvider({ children }) {
  const { user } = useAuth()
  const notifications = useNotifications()
  const notify = useRef(notifications)
  useEffect(() => { notify.current = notifications }, [notifications])
  const eligible = ['STUDENT', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR'].includes(user?.role)
  const [count, setCount] = useState(0)
  const [revision, setRevision] = useState(0)
  const [recovery, setRecovery] = useState(0)
  const [status, setStatus] = useState('offline')
  const alive = useRef(false)
  const sequence = useRef(0)
  const controller = useRef(null)
  const socket = useRef(null)
  const listeners = useRef(new Map())
  const refresh = useCallback(async () => {
    if (!alive.current) return
    const current = ++sequence.current
    controller.current?.abort()
    const abort = new AbortController(); controller.current = abort
    try {
      const result = await getUnreadMessageCount(abort.signal)
      if (alive.current && current === sequence.current) {
        setCount(Number.isSafeInteger(result.unread_conversations) ? Math.max(0, result.unread_conversations) : 0)
        setRevision(value => value + 1)
      }
    } catch (error) {
      if (alive.current && current === sequence.current && [401, 403].includes(error?.status)) {
        setCount(0); socket.current?.disconnect(); setStatus('offline')
      }
    }
  }, [])
  useEffect(() => {
    let active = true
    let connection = null
    const cancel = () => controller.current?.abort()
    const invalidate = () => { ++sequence.current }
    alive.current = eligible
    if (eligible) {
      const change = () => { refresh(); notify.current.invalidate?.() }
      connection = connectMessaging(user.id, change, () => { if (active) { change(); setRecovery(value => value + 1) } }, value => { if (active) setStatus(value) })
      socket.current = connection
      for (const record of listeners.current.values()) record.stop = connection.subscribe(record.id, record.callback)
      queueMicrotask(() => { if (active) refresh() })
    }
    const focus = () => { if (active && document.visibilityState === 'visible') { refresh(); setRecovery(value => value + 1) } }
    window.addEventListener('focus', focus); document.addEventListener('visibilitychange', focus)
    return () => {
      active = false; alive.current = false; invalidate()
      cancel(); connection?.disconnect(); socket.current = null
      window.removeEventListener('focus', focus); document.removeEventListener('visibilitychange', focus)
    }
  }, [user?.id, user?.role, eligible, refresh])
  const subscribe = useCallback((id, callback) => {
    const key = Symbol(id)
    const record = { id, callback, stop: socket.current?.subscribe(id, callback) }
    listeners.current.set(key, record)
    return () => { record.stop?.(); listeners.current.delete(key) }
  }, [])
  return <MessagingContext.Provider value={{ count: eligible ? count : 0, revision, recovery, status, refresh, subscribe }}>{children}</MessagingContext.Provider>
}
