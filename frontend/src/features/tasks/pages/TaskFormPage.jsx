import { useEffect } from 'react'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { PageHeading } from '../../users/components/UserUi'
import { getSupervisorInternship, getSupervisorTask } from '../api/tasksApi'
import { useTaskRecord } from '../hooks/useTaskRecord'
import { RecordLoading } from '../components/TaskUi'
import { TaskForm } from '../components/TaskForm'

export function TaskFormPage() {
  const { id, internshipId } = useParams()
  return <FormPage key={id ? `edit-${id}` : `new-${internshipId}`} id={id} internshipId={internshipId} />
}

function FormPage({ id, internshipId }) {
  const navigate = useNavigate()
  const { data, error, reload } = useTaskRecord(id ? getSupervisorTask : getSupervisorInternship, id ?? internshipId)
  const eligible = id ? data?.can_edit : data?.can_create_tasks
  const back = id ? `/supervisor/tasks/${id}` : `/supervisor/internships/${internshipId}`
  useEffect(() => { if (data && !eligible) navigate(back, { replace: true, state: { conflict: 'Gjendja nuk lejon këtë redaktim. Të dhënat u rifreskuan.' } }) }, [data, eligible, navigate, back])
  if (error || !data || !eligible) return <RecordLoading error={error} reload={reload} />
  return <div className="task-feature"><Link className="internship-back" to={back}>← Kthehu te detajet</Link><PageHeading eyebrow="Menaxhimi i detyrave" title={id ? 'Ndrysho detyrën' : 'Krijo detyrë'} description={id ? data.internship.position_title : data.position_title} /><TaskForm task={id ? data : null} internshipId={id ? data.internship.id : data.id} /></div>
}
