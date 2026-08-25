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
`internflow` exists. Then run:

```sh
cd backend
composer install
php artisan migrate
php artisan serve
```

Local `.env` files and credentials must not be committed.

## Database schema notes

Workflow statuses, task priorities, feedback decisions, internship date order,
and task progress bounds use PostgreSQL `CHECK` constraints. String columns plus
checks were chosen instead of PostgreSQL enum types so allowed values remain easy
to change with later migrations. Historical internship records use restrictive
foreign-key deletes; profile rows may be deleted with their user only when no
historical record still references them.
