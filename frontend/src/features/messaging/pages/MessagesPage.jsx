import { useCallback, useEffect, useLayoutEffect, useRef, useState } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { useAuth } from '../../auth/hooks/useAuth'
import { getConversation, listConversations, listEligibleInternships, listMessages, markConversationRead, mergeMessages, messagingError, openConversation, positiveId, sendMessage } from '../api/messagingApi'
import { useMessaging } from '../context/MessagingContext'
import '../styles/messaging.css'

const names = (conversation, userId) => conversation.participants.filter(user => user.id !== userId).map(user => `${user.first_name} ${user.last_name}`).join(', ')
const date = stamp => stamp ? new Date(stamp).toLocaleString('sq-AL', { dateStyle: 'short', timeStyle: 'short' }) : ''
const cursor = result => result.meta?.next_cursor ?? (result.links?.next ? new URL(result.links.next, window.location.origin).searchParams.get('cursor') : null)
const typeNames = { student_supervisor: 'Studenti dhe mbikëqyrësi', student_coordinator: 'Studenti dhe koordinatori', group: 'Grupi i praktikës' }
const internshipContext = conversation => [`Praktika #${conversation.internship_id}`, conversation.internship?.position_title, conversation.internship?.company_name].filter(Boolean).join(' · ')

function NewConversation({ close }) {
  const navigate = useNavigate()
  const { refresh } = useMessaging()
  const [page, setPage] = useState(1)
  const [options, setOptions] = useState(null)
  const [internship, setInternship] = useState('')
  const [type, setType] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)
  const pending = useRef(false)
  const alive = useRef(true)
  useEffect(() => {
    alive.current = true
    return () => { alive.current = false }
  }, [])
  useEffect(() => {
    let active = true
    const abort = new AbortController()
    queueMicrotask(() => { if (active) { setOptions(null); setInternship(''); setType(''); setError('') } })
    listEligibleInternships({ page, per_page: 15 }, abort.signal).then(result => { if (active) setOptions(result) }).catch(failure => { if (active && !abort.signal.aborted) setError(messagingError(failure)) })
    return () => { active = false; abort.abort() }
  }, [page, retry])
  async function start(event) {
    event.preventDefault()
    if (pending.current || !internship || !type) return
    pending.current = true; setBusy(true); setError('')
    try {
      const conversation = await openConversation(internship, type)
      if (alive.current) { refresh(); close(); navigate(`/messages/conversations/${conversation.id}`) }
    } catch (failure) { if (alive.current) setError(messagingError(failure)) }
    finally { pending.current = false; if (alive.current) setBusy(false) }
  }
  const selected = options?.data.find(item => String(item.id) === internship)
  return <section className="messages-new" aria-label="Bisedë e re"><div className="messages-row"><h2>Fillo një bisedë</h2><button type="button" onClick={close} aria-label="Mbyll bisedën e re">Mbyll</button></div>
    {error && <p role="alert">{error} <button type="button" onClick={() => setRetry(value => value + 1)}>Provo përsëri</button></p>}
    {!options && !error && <p role="status">Duke ngarkuar praktikat…</p>}
    {options && <form onSubmit={start}><label>Praktika<select value={internship} required onChange={event => { setInternship(event.target.value); setType('') }}><option value="">Zgjidh praktikën</option>{options.data.map(item => <option key={item.id} value={item.id} disabled={!item.available_types.length}>#{item.id} · {item.position_title} · {item.company_name}</option>)}</select></label>
      <label>Pjesëmarrësit<select value={type} required disabled={!selected} onChange={event => setType(event.target.value)}><option value="">Zgjidh llojin</option>{selected?.available_types.map(value => <option key={value} value={value}>{typeNames[value]}</option>)}</select></label>
      {!options.data.length && <p>Nuk ka praktika të miratuara ose aktive të disponueshme.</p>}
      <div className="messages-row"><button type="button" disabled={page === 1 || busy} onClick={() => setPage(value => value - 1)}>Para</button><span>Faqja {page}</span><button type="button" disabled={page >= options.last_page || busy} onClick={() => setPage(value => value + 1)}>Pas</button></div>
      <button className="messages-primary" disabled={busy || !internship || !type}>{busy ? 'Duke hapur…' : 'Hap bisedën'}</button></form>}
  </section>
}

function ConversationView({ id }) {
  const { user } = useAuth()
  const { refresh, subscribe, recovery } = useMessaging()
  const [conversation, setConversation] = useState(null)
  const [messages, setMessages] = useState([])
  const [older, setOlder] = useState(null)
  const [loading, setLoading] = useState(true)
  const [historyError, setHistoryError] = useState('')
  const [readError, setReadError] = useState('')
  const [text, setText] = useState('')
  const [sending, setSending] = useState(false)
  const [sendError, setSendError] = useState('')
  const [uncertain, setUncertain] = useState(null)
  const [retry, setRetry] = useState(0)
  const [view, setView] = useState(0)
  const [olderBusy, setOlderBusy] = useState(false)
  const [atBottom, setAtBottom] = useState(true)
  const alive = useRef(false)
  const sequence = useRef(0)
  const abort = useRef(null)
  const history = useRef([])
  const scroller = useRef(null)
  const header = useRef(null)
  const stick = useRef(true)
  const anchor = useRef(null)
  const readId = useRef(0)
  const readSequence = useRef(0)
  const pending = useRef(false)
  useLayoutEffect(() => { history.current = messages }, [messages])
  const loadLatest = useCallback(async (initial = false) => {
    const current = ++sequence.current
    abort.current?.abort(); const controller = new AbortController(); abort.current = controller
    if (initial) setLoading(true)
    try {
      const [details, latest] = await Promise.all([getConversation(id, controller.signal), listMessages(id, {}, controller.signal)])
      let collected = latest.data
      let next = cursor(latest)
      const existing = new Set(history.current.map(item => item.id))
      while (!initial && existing.size && next && !collected.some(item => existing.has(item.id))) {
        const page = await listMessages(id, { cursor: next }, controller.signal)
        collected = [...collected, ...page.data]; next = cursor(page)
      }
      if (!alive.current || current !== sequence.current) return
      setConversation(details); setHistoryError('')
      setMessages(currentMessages => initial ? mergeMessages([], collected) : mergeMessages(currentMessages, collected))
      if (initial || !history.current.length) setOlder(next)
    } catch (failure) {
      if (alive.current && current === sequence.current && !controller.signal.aborted) {
        setHistoryError(messagingError(failure))
        if ([401, 403, 404].includes(failure?.status)) { setConversation(null); setMessages([]); setOlder(null) }
      }
    } finally { if (alive.current && current === sequence.current) setLoading(false) }
  }, [id])
  useEffect(() => {
    alive.current = true
    const cancel = () => abort.current?.abort()
    const invalidate = () => { ++sequence.current; ++readSequence.current }
    queueMicrotask(() => {
      if (!alive.current) return
      if (positiveId(id)) loadLatest(true)
      else { setHistoryError('Biseda nuk është e disponueshme.'); setLoading(false) }
    })
    header.current?.focus()
    const unsubscribe = positiveId(id) ? subscribe(id, () => loadLatest()) : () => {}
    const visible = () => setView(value => value + 1)
    document.addEventListener('visibilitychange', visible)
    return () => { alive.current = false; invalidate(); cancel(); unsubscribe(); document.removeEventListener('visibilitychange', visible) }
  }, [id, retry, subscribe, loadLatest])
  useEffect(() => { if (alive.current && positiveId(id) && recovery > 0) loadLatest() }, [recovery, id, loadLatest])
  useLayoutEffect(() => {
    const element = scroller.current
    if (!element) return
    if (anchor.current !== null) { element.scrollTop += element.scrollHeight - anchor.current; anchor.current = null }
    else if (stick.current) element.scrollTop = element.scrollHeight
  }, [messages])
  const loadedConversationId = conversation?.id
  useEffect(() => {
    if (loading || historyError || !loadedConversationId || !messages.length || !stick.current || document.visibilityState !== 'visible') return
    const lastId = Math.max(...messages.map(item => item.id))
    if (lastId <= readId.current) return
    readId.current = lastId
    const current = ++readSequence.current
    markConversationRead(id, lastId).then(updated => {
      if (current === readSequence.current && alive.current) { setConversation(updated); setReadError(''); refresh() }
    }).catch(failure => { if (current === readSequence.current && alive.current) { readId.current = 0; setReadError(messagingError(failure)) } })
  }, [messages, loading, historyError, loadedConversationId, id, view, refresh])
  async function loadOlder() {
    if (!older || olderBusy) return
    stick.current = false; setAtBottom(false); setOlderBusy(true)
    try {
      const result = await listMessages(id, { cursor: older })
      if (alive.current) {
        anchor.current = scroller.current?.scrollHeight ?? null
        setMessages(current => mergeMessages(current, result.data)); setOlder(cursor(result)); setHistoryError('')
      }
    } catch (failure) { if (alive.current) setHistoryError(messagingError(failure)) }
    finally { if (alive.current) setOlderBusy(false) }
  }
  async function submit(event) {
    event?.preventDefault()
    const content = text.trim()
    if (pending.current || uncertain || !content || [...content].length > 2000 || !conversation?.can_send) return
    pending.current = true; setSending(true); setSendError('')
    const baseline = Math.max(0, ...history.current.map(item => item.id))
    try {
      const result = await sendMessage(id, content)
      if (alive.current) { stick.current = true; setAtBottom(true); setMessages(current => mergeMessages(current, [result])); setText(''); refresh() }
    } catch (failure) {
      if (alive.current) {
        if (!failure?.status || failure.status >= 500) {
          setUncertain({ content, baseline }); setSendError('Dorëzimi nuk u konfirmua. Kontrolloni historinë; mos e ridërgoni automatikisht.'); loadLatest()
        } else setSendError(messagingError(failure))
      }
    } finally { pending.current = false; if (alive.current) setSending(false) }
  }
  useEffect(() => {
    if (uncertain && messages.some(item => item.id > uncertain.baseline && item.sender_id === user.id && item.content === uncertain.content)) {
      let active = true
      queueMicrotask(() => { if (active) { setUncertain(null); setSendError(''); setText('') } })
      return () => { active = false }
    }
  }, [messages, uncertain, user.id])
  return <section className="messages-chat" aria-label="Biseda"><header className="messages-chat-header"><Link className="messages-back" to="/messages">← Bisedat</Link><h2 ref={header} tabIndex="-1">{conversation ? names(conversation, user.id) : 'Biseda'}</h2>{conversation && <p>{internshipContext(conversation)} · {conversation.type === 'group' ? 'Bisedë grupi' : 'Bisedë direkte'}</p>}</header>
    {loading && <p className="messages-state" role="status">Duke ngarkuar mesazhet…</p>}
    {historyError && <div className="messages-state" role="alert"><p>{historyError}</p><button onClick={() => setRetry(value => value + 1)}>Provo përsëri</button></div>}
    {conversation && <><div ref={scroller} className="messages-history" role="region" aria-label="Historia e mesazheve" tabIndex="0" onScroll={() => {
      const element = scroller.current; stick.current = element.scrollHeight - element.scrollTop - element.clientHeight < 48; setAtBottom(stick.current); setView(value => value + 1)
    }}>
      {older && <button className="messages-older" disabled={olderBusy} onClick={loadOlder}>{olderBusy ? 'Duke ngarkuar…' : 'Mesazhe më të vjetra'}</button>}
      {!loading && !messages.length && <p className="messages-state">Nuk ka mesazhe ende. Filloni bisedën.</p>}
      <ol>{messages.map(item => <li key={item.id} className={`messages-bubble ${item.sender_id === user.id ? 'is-own' : 'is-incoming'}`}><span className="messages-sender">{item.sender_id === user.id ? 'Ju' : `${item.sender.first_name} ${item.sender.last_name}`}</span><p>{item.content}</p><time dateTime={item.sent_at}>{date(item.sent_at)}</time></li>)}</ol>
    </div><p className="messages-visually-hidden" role="status" aria-live="polite">{messages.length ? `${messages.length} mesazhe të ngarkuara.` : ''}</p>
    {!atBottom && <button onClick={() => { stick.current = true; setAtBottom(true); scroller.current.scrollTop = scroller.current.scrollHeight; setView(value => value + 1) }}>Shko te mesazhet e fundit ↓</button>}
    {readError && <p className="messages-state" role="alert">{readError} <button onClick={() => setView(value => value + 1)}>Provo leximin përsëri</button></p>}
    {conversation.can_send ? <form className="messages-composer" onSubmit={submit}><label htmlFor="message-content">Mesazhi juaj</label><textarea id="message-content" rows="3" value={text} disabled={sending || !!uncertain} onChange={event => setText(event.target.value)} aria-describedby="message-hint message-send-error" onKeyDown={event => {
      if (event.key === 'Enter' && !event.shiftKey && !event.nativeEvent.isComposing) { event.preventDefault(); submit() }
    }} /><div className="messages-row"><small id="message-hint">{[...text].length}/2000 · Shift+Enter për rresht të ri</small><button className="messages-primary" disabled={sending || !!uncertain || !text.trim() || [...text.trim()].length > 2000}>{sending ? 'Duke dërguar…' : 'Dërgo'}</button></div>
    <p id="message-send-error" role={sendError ? 'alert' : undefined}>{sendError}</p>{uncertain && <div className="messages-row"><button type="button" onClick={() => loadLatest()}>Kontrollo dorëzimin</button><button type="button" onClick={() => { setUncertain(null); setText(''); setSendError('') }}>Anulo tekstin e mbajtur</button></div>}</form> : <p className="messages-readonly">Praktika ka përfunduar. Historia është vetëm për lexim.</p>}</>}
  </section>
}

export function MessagesPage() {
  const { user } = useAuth()
  const { conversationId } = useParams()
  const { count, revision, status } = useMessaging()
  const [filter, setFilter] = useState('all')
  const [search, setSearch] = useState('')
  const [query, setQuery] = useState('')
  const [page, setPage] = useState(1)
  const [result, setResult] = useState(null)
  const [error, setError] = useState('')
  const [retry, setRetry] = useState(0)
  const [create, setCreate] = useState(false)
  useEffect(() => {
    let active = true; const abort = new AbortController()
    queueMicrotask(() => { if (active) { setResult(null); setError('') } })
    listConversations({ filter, search: query, page, per_page: 15 }, abort.signal).then(data => { if (active) setResult(data) }).catch(failure => { if (active && !abort.signal.aborted) setError(messagingError(failure)) })
    return () => { active = false; abort.abort() }
  }, [filter, query, page, revision, retry])
  return <div className={`messages-page${conversationId ? ' has-conversation' : ''}`} onKeyDown={event => { if (event.key === 'Escape' && create) setCreate(false) }}>
    <aside className="messages-sidebar" aria-label="Lista e bisedave"><div className="messages-title"><div><h1>Mesazhet <span className="messages-badge" aria-label={`${count} biseda të palexuara`}>{count > 99 ? '99+' : count}</span></h1><p className="messages-connection">{status === 'connected' ? '● Në kohë reale' : 'Lidhja në kohë reale nuk është aktive.'}</p></div><button aria-expanded={create} onClick={() => setCreate(value => !value)}>Bisedë e re</button></div>
      {create && <NewConversation close={() => setCreate(false)} />}
      <form className="messages-search" onSubmit={event => { event.preventDefault(); setQuery(search.trim()); setPage(1) }}><label className="messages-visually-hidden" htmlFor="conversation-search">Kërko emër ose praktikë</label><input id="conversation-search" type="search" maxLength="100" value={search} onChange={event => setSearch(event.target.value)} placeholder="Kërko emër ose praktikë…" /><button>Kërko</button></form>
      <div className="messages-filters" aria-label="Filtrat e bisedave">{[['all', 'Të gjitha'], ['unread', 'Të palexuara'], ['read', 'Të lexuara']].map(([value, label]) => <button key={value} aria-pressed={filter === value} onClick={() => { setFilter(value); setPage(1) }}>{label}</button>)}</div>
      {error ? <div className="messages-state" role="alert"><p>{error}</p><button onClick={() => setRetry(value => value + 1)}>Provo përsëri</button></div> : !result ? <p className="messages-state" role="status">Duke ngarkuar bisedat…</p> : <><ul className="messages-conversations">{result.data.map(item => <li key={item.id}><Link className={`${String(item.id) === conversationId ? 'is-selected ' : ''}${item.has_unread_messages ? 'is-unread' : ''}`} to={`/messages/conversations/${item.id}`} aria-current={String(item.id) === conversationId ? 'page' : undefined}><div className="messages-row"><strong>{names(item, user.id)}</strong>{item.has_unread_messages && <span className="messages-badge" aria-label={`${item.unread_messages} mesazhe të palexuara`}>{item.unread_messages}</span>}</div><small>{internshipContext(item)}</small><p>{item.latest_message?.preview || 'Nuk ka mesazhe ende.'}</p><time dateTime={item.latest_message?.sent_at || item.created_at}>{date(item.latest_message?.sent_at || item.created_at)}</time></Link></li>)}</ul>{!result.data.length && <p className="messages-state">Nuk u gjetën biseda për këtë filtër.</p>}<div className="messages-pagination"><button disabled={page === 1} onClick={() => setPage(value => value - 1)}>Para</button><span>{page}/{result.meta.last_page}</span><button disabled={page >= result.meta.last_page} onClick={() => setPage(value => value + 1)}>Pas</button></div></>}
    </aside>{conversationId ? <ConversationView key={conversationId} id={conversationId} /> : <section className="messages-placeholder"><span aria-hidden="true">✉</span><h2>Bisedat e praktikës</h2><p>Zgjidhni një bisedë ose filloni një të re me pjesëmarrësit e praktikës suaj.</p></section>}
  </div>
}
