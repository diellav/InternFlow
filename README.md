
# InternFlow

[🇦🇱 Lexo në shqip](README.sq.md)

**InternFlow** is a web-based Student Internship Management System developed as a bachelor's thesis project.

The application brings together students, academic coordinators, company supervisors, and administrators to manage and monitor the internship process, from initial registration and approval to task completion and final evaluation.

## Technologies

- **Frontend:** React, Vite, JavaScript
- **Backend:** Laravel (PHP)
- **Database:** PostgreSQL
- **Authentication:** Laravel Sanctum
- **Real-time communication:** Laravel Reverb and WebSockets

## Main Features

### Student
- Register and submit internship applications for academic approval.
- View internship details and track application statuses.
- Review assigned tasks and submit completed work.
- Record internship activities and working hours.
- Communicate with academic coordinators and company supervisors.
- Receive notifications about internship updates.
- View feedback and final internship evaluations.

### Academic Coordinator
- Review internship applications and approve, reject, or request corrections.
- Monitor internship progress and student activities.
- Review internship-related information and evaluations.
- Officially complete internships after all required conditions have been met.

### Company Supervisor
- Register as a supervisor and associate with an existing or newly registered company.
- Monitor assigned students and their internships.
- Create and manage internship tasks.
- Review task submissions and provide feedback.
- Evaluate student performance at the end of the internship.

### Administrator
- Manage users, roles, account statuses, coordinators, and companies.
- Access internship records and administrative information.
- Review audit logs and important system activities.

### Additional Features
- Role-based access control.
- Real-time messaging between authorized internship participants.
- Notifications for important internship activities.
- Internship completion validation based on required tasks, approvals, final evaluation, and the scheduled end date.
- Audit logging for tracking relevant system actions.

## Project Structure

The project is organized into two main directories:

- `frontend/` — React application built with Vite.
- `backend/` — Laravel application connected to PostgreSQL.

The frontend handles user interaction and application interfaces, while the backend processes requests, enforces business rules, manages authentication, and communicates with the database.

## Local Setup

### Prerequisites

Before running the application, make sure the following tools are installed:

- Node.js and npm
- PHP and Composer
- PostgreSQL

### Frontend

Open a terminal in the project directory and run:

```sh
cd frontend
npm install
npm run dev
```

By default, the frontend runs at:

`http://localhost:5173`

### Backend

1. Navigate to the `backend/` directory.
2. Copy `.env.example` to `.env`.
3. Configure the PostgreSQL connection in the `.env` file.
4. Create a PostgreSQL database named `internflow`.
5. Configure the initial administrator credentials if needed.

The relevant environment variables include:

```env
DB_CONNECTION=pgsql
DB_DATABASE=internflow
DB_USERNAME=your_postgres_username
DB_PASSWORD=your_postgres_password

ADMIN_EMAIL=your_admin_email
ADMIN_PASSWORD=your_admin_password
```

The initial administrator is created through the database seeder only when both `ADMIN_EMAIL` and `ADMIN_PASSWORD` are provided.

Run the following commands from the project root:

```sh
cd backend
composer install
php artisan migrate
php artisan db:seed
php artisan serve
```

By default, the backend runs at:

`http://localhost:8000`

**Security note:** Local `.env` files, passwords, and other sensitive credentials must not be committed to GitHub.

## Authentication and Session Configuration

InternFlow uses Laravel Sanctum for stateful, cookie-based SPA authentication.

Configure `FRONTEND_URL` and `SANCTUM_STATEFUL_DOMAINS` according to the frontend origin.

The default local development addresses are:

- Frontend: `http://localhost:5173`
- Backend: `http://localhost:8000`

Authentication supports login, logout, current-user session restoration, student registration, and company-supervisor registration.

## Testing

### Frontend

Run the following commands from the `frontend/` directory:

```sh
npm run test
npm run lint
npm run build
```

These commands run the frontend tests, check the code for linting issues, and create a production build.

### Backend

Backend tests use a separate PostgreSQL database named `internflow_testing`.

Before running tests that require database migrations, create this database and configure the necessary PostgreSQL credentials locally.

The backend test suite can be run from the `backend/` directory using:

```sh
php artisan test
```

## Database Design

The PostgreSQL database includes constraints to support data integrity and the internship workflow.

`CHECK` constraints are used for:

- Internship and workflow statuses
- Task priorities and progress values
- Feedback decisions
- Internship date ordering
- Submission version numbers
- Activity hours
- Evaluation scores

String columns with `CHECK` constraints are used instead of PostgreSQL enum types to make allowed values easier to modify through future migrations.

Foreign-key constraints protect historical internship records from unintended deletion. Where appropriate, `SET NULL` is used to preserve historical records when related verification or audit users are deleted.

## Internship Completion

An internship can be officially completed only when the required conditions are satisfied:

1. The student has submitted all required tasks.
2. The company supervisor has approved the task submissions.
3. The company supervisor has provided the final internship evaluation.
4. The scheduled internship end date has been reached.

Once these conditions are fulfilled, the academic coordinator can officially finalize the internship.

## Academic Project

InternFlow was developed as part of a bachelor's thesis in **Computer Science and Engineering at UBT**.

**Thesis title:** Design and Implementation of a Student Internship Management System.

The project demonstrates the design, implementation, and testing of a web-based system for managing student internships.
