export function InternshipDecisionSummary({ internship, student = false }) {
  const explanation = {
    SUBMITTED: 'Aplikimi është dorëzuar dhe pret fillimin e shqyrtimit akademik.',
    UNDER_REVIEW: 'Koordinatori akademik po shqyrton aplikimin. Vendimi ende nuk është regjistruar.',
    APPROVED: 'Aplikimi është miratuar. Praktika nuk është aktivizuar automatikisht.',
    REJECTED: 'Aplikimi është refuzuar dhe mbetet vetëm për lexim.',
    REVISION_REQUIRED: student ? 'Koordinatori kërkon korrigjime. Ndryshoni aplikimin dhe ruani korrigjimet, pastaj ridorëzojeni veçmas. Vetëm ruajtja nuk e kthen aplikimin në radhën e shqyrtimit.' : 'Studentit i janë kërkuar korrigjime. Ky vendim nuk mund të ndryshohet përmes shqyrtimit aktual.',
  }[internship.status]
  if (!explanation) return null
  return <section className="internship-help internship-decision-summary" aria-label="Gjendja e shqyrtimit"><strong>{['APPROVED', 'REJECTED', 'REVISION_REQUIRED'].includes(internship.status) ? 'Vendimi akademik' : 'Shqyrtimi akademik'}</strong><p>{explanation}</p>{internship.decision_comment && <><h2>{internship.status === 'REVISION_REQUIRED' ? 'Udhëzimet për korrigjim' : 'Arsyeja e vendimit'}</h2><p className="internship-decision-comment">{internship.decision_comment}</p></>}{internship.status === 'REVISION_REQUIRED' && <p>Ruhen vetëm udhëzimet aktuale, jo historiku i shqyrtimeve. Pas ridorëzimit, këto udhëzime pastrohen.</p>}{internship.status === 'APPROVED' && internship.approved_at && <p>Miratuar më: <time dateTime={internship.approved_at}>{new Date(internship.approved_at).toLocaleString('sq-AL')}</time></p>}</section>
}
