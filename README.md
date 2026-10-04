# InternFlow

Bachelor thesis project for a web-based Student Internship Management System.

## Project structure

- `frontend/` — React with Vite (JavaScript)
- `backend/` — Laravel with PostgreSQL

## Local setup

### Frontend

```sh
cd frontend
npm install
npm run dev
```

### Backend

Copy `backend/.env.example` to `backend/.env`, set `DB_USERNAME` and
`DB_PASSWORD` for your PostgreSQL installation, and ensure a database named
`internflow` exists. To provision the initial development Admin, also set
`ADMIN_EMAIL` and `ADMIN_PASSWORD`; the seeder safely skips Admin creation when
either value is absent. Then run:

```sh
cd backend
composer install
php artisan migrate
php artisan db:seed
php artisan serve
```

Local `.env` files and credentials must not be committed.

Backend tests are configured for a separate PostgreSQL database named
`internflow_testing`. Create it and provide PostgreSQL credentials locally before
running tests that use database migrations.

## Database schema notes

Workflow statuses, task priorities, feedback decisions, internship date order,
task progress bounds, submission version numbers, activity hours, and evaluation
scores use PostgreSQL `CHECK` constraints. String columns plus checks were chosen
instead of PostgreSQL enum types so allowed values remain easy to change with
later migrations. Historical internship records use restrictive foreign-key
deletes; profile rows may be deleted with their user only when no historical
record still references them. Verification reviewers and audit users use
`SET NULL` deletion so historical records remain intact.
