import { useCallback, useState } from 'react'
import { useActivityRecord } from '../../activities/hooks/useActivityRecord'
import { ActivityState } from '../../activities/components/ActivityState'
import { monitoringErrorMessage } from '../api/monitoringApi'

export function MonitoringRecords({ loader, id, children, empty = 'Nuk ka regjistra.' }) {
  const [page, setPage] = useState(1)
  const request = useCallback((recordId, signal) => loader(recordId, { page, per_page: 15 }, signal), [loader, page])
  const { data, error, reload } = useActivityRecord(request, id, monitoringErrorMessage)
  if (error || !data) return <ActivityState error={error} reload={reload} />
  return <>{!data.data.length ? <p className="internship-empty">{empty}</p> : children(data.data)}<div className="internship-pagination"><span>{data.meta.total} regjistra · Faqja {data.meta.current_page} nga {data.meta.last_page}</span><div><button className="admin-button" disabled={page <= 1} onClick={() => setPage(page - 1)}>Para</button><button className="admin-button" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>Pas</button></div></div></>
}
