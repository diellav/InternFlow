export function ActivityState({ error, reload }) {
  return <section className="internship-empty">{error ? <><p role="alert">{error}</p><button className="admin-button" onClick={reload}>Provo përsëri</button></> : <p role="status">Duke ngarkuar aktivitetet…</p>}</section>
}
