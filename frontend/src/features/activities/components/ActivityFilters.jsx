import { useState } from 'react'

function validDate(value) {
  const parsed = new Date(`${value}T00:00:00Z`)
  return /^\d{4}-\d{2}-\d{2}$/.test(value) && value.slice(0, 4) !== '0000' && !Number.isNaN(parsed.getTime()) && parsed.toISOString().slice(0, 10) === value
}

export function ActivityFilters({ filters, onApply, onClear }) {
  const [values, setValues] = useState(filters)
  const [errors, setErrors] = useState({})
  function change(event) {
    const { name, value } = event.target
    setValues((current) => ({ ...current, [name]: value }))
    setErrors((current) => ({ ...current, [name]: undefined }))
  }
  function apply(event) {
    event.preventDefault()
    const invalid = {}
    for (const field of ['date_from', 'date_to']) if (values[field] && !validDate(values[field])) invalid[field] = 'Vendosni një datë të vlefshme.'
    if (!invalid.date_from && !invalid.date_to && values.date_from && values.date_to && values.date_from > values.date_to) invalid.date_to = 'Data e fundit duhet të jetë e njëjtë ose pas datës së fillimit.'
    if ([...values.search.trim()].length > 255) invalid.search = 'Kërkimi lejon maksimumi 255 karaktere.'
    setErrors(invalid)
    if (Object.keys(invalid).length) return
    onApply({ ...values, search: values.search.trim() })
  }
  function attrs(field) {
    return { id: `activity-filter-${field}`, name: field, value: values[field], onChange: change, 'aria-invalid': Boolean(errors[field]), 'aria-describedby': errors[field] ? `activity-filter-${field}-error` : undefined }
  }
  function error(field) { return errors[field] && <span className="field-error" id={`activity-filter-${field}-error`}>{errors[field]}</span> }
  return <form className="internship-panel activity-filters" aria-label="Filtrat e aktiviteteve" onSubmit={apply} noValidate><div className="activity-filter-grid"><div className="form-field"><label htmlFor="activity-filter-date_from">Data nga</label><input {...attrs('date_from')} type="date" />{error('date_from')}</div><div className="form-field"><label htmlFor="activity-filter-date_to">Data deri</label><input {...attrs('date_to')} type="date" />{error('date_to')}</div><div className="form-field activity-search-field"><label htmlFor="activity-filter-search">Kërko në titull ose përshkrim</label><input {...attrs('search')} type="search" maxLength={255} />{error('search')}</div></div><div className="activity-filter-actions"><button className="admin-button admin-primary" type="submit">Apliko filtrat</button><button className="admin-button" type="button" onClick={() => { setValues({ date_from: '', date_to: '', search: '' }); setErrors({}); onClear() }}>Pastro filtrat</button></div></form>
}
