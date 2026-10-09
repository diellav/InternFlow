import { useCallback } from 'react'
import { useSearchParams } from 'react-router-dom'
import { activityErrorMessage, listActivities, listSupervisorActivities, supervisorActivityErrorMessage } from '../api/activitiesApi'
import { useActivityRecord } from '../hooks/useActivityRecord'
import { ActivityFilters } from './ActivityFilters'
import { ActivityHoursSummary } from './ActivityHoursSummary'
import { ActivityRecords } from './ActivityRecords'
import { ActivityState } from './ActivityState'

export function ActivityListPanel({ internshipId, supervisor = false, listRequest, errorMessage, recordsBase }) {
  const [params, setParams] = useSearchParams()
  const dateFrom = String(params.get('date_from') ?? '')
  const dateTo = String(params.get('date_to') ?? '')
  const search = String(params.get('search') ?? '')
  const page = Number(params.get('page') ?? 1)
  const filters = { date_from: dateFrom, date_to: dateTo, search }
  const filtered = Boolean(dateFrom || dateTo || search.trim())
  const setPage = useCallback((nextPage) => setParams((current) => {
    const next = new URLSearchParams(current)
    if (nextPage === 1) next.delete('page')
    else next.set('page', String(nextPage))
    return next
  }), [setParams])
  const loader = useCallback(async (id, signal) => {
    const criteria = Object.fromEntries(Object.entries({ date_from: dateFrom, date_to: dateTo, search }).filter(([, value]) => value !== ''))
    const response = await (listRequest ?? (supervisor ? listSupervisorActivities : listActivities))(id, { page, per_page: 15, ...criteria }, signal)
    if (!signal.aborted && response.meta.current_page > response.meta.last_page) setPage(response.meta.last_page)
    return response
  }, [dateFrom, dateTo, search, page, supervisor, setPage, listRequest])
  const { data: result, error, reload } = useActivityRecord(loader, internshipId, errorMessage ?? (supervisor ? supervisorActivityErrorMessage : activityErrorMessage))
  function apply(nextFilters) {
    setParams((current) => {
      const next = new URLSearchParams(current)
      next.delete('page')
      for (const [field, value] of Object.entries(nextFilters)) {
        if (value === '') next.delete(field)
        else next.set(field, value)
      }
      return next
    })
  }
  return <><ActivityFilters key={JSON.stringify(filters)} filters={filters} onApply={apply} onClear={() => apply({ date_from: '', date_to: '', search: '' })} />{error || !result ? <ActivityState error={error} reload={reload} /> : <><ActivityHoursSummary result={result} /><ActivityRecords result={result} page={page} setPage={setPage} supervisor={supervisor} filtered={filtered} recordsBase={recordsBase} /></>}</>
}
