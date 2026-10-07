export function AccountBadge({ active }) {
  return <span className={`account-badge ${active ? 'is-active' : 'is-inactive'}`}><span aria-hidden="true" />{active ? 'Aktive' : 'Joaktive'}</span>
}

export function PageHeading({ eyebrow = 'Administrimi', title, description, children }) {
  return <div className="admin-page-heading"><div><p className="admin-eyebrow">{eyebrow}</p><h1>{title}</h1>{description && <p>{description}</p>}</div><div className="admin-heading-actions">{children}</div></div>
}
