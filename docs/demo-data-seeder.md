# Additive demo dataset

`InternFlowDemoSeeder` is opt-in and is not registered in `DatabaseSeeder`. Run it only after explicit approval, with the existing local PostgreSQL configuration and schema. No migrations are needed.

```powershell
cd C:\Users\Admin\InternFlow\backend
php artisan db:seed --class=InternFlowDemoSeeder
```

The dataset contains 10 active accounts (5 students, 2 coordinators, 3 approved supervisors), 3 active approved fictional companies, 7 internships, 10 tasks, 6 submissions, 4 feedback records, 9 activities (36 hours total), one submitted final evaluation, 2 group conversations and 18 historical messages. Internship statuses: SUBMITTED 1, REJECTED 1, APPROVED 2, ACTIVE 2, COMPLETED 1.

Emails use lowercase `first.last@test.com` for the names in the requested dataset. New accounts use the hashed demo password `....`. These are local demo credentials only. No admin account is created. Existing roles must already exist. Company descriptions use the existing verification note because the schema has no description column; `.example` websites and Demo addresses are fictional.

An atomic transaction and PostgreSQL advisory lock protect the batch. Case-insensitive user-email/company-name collisions or demo student-number collisions stop the batch without adopting or overwriting existing records. An audit-log marker (`internflow.demo.seed.v1`, null actor, `demo_dataset`) stores created user/company/internship IDs. After successful seeding, subsequent runs are no-ops, including if demo records have since been edited or deleted. Do not remove the marker to reseed; this is deliberately not a repair/reset tool.

The submitted internship is unassigned. Other internships have valid coordinator/supervisor assignments. Completed evidence has approved tasks, reviewed submissions and a submitted evaluation preceding completion. Dates are relative to the first run; activities fall within their internship periods. No files or attachments are seeded.

Conversations are opened through the existing messaging service with authorized assigned participants. Messages are inserted as fictional historical model records, not sent as new workflow events; no per-message notifications or broadcasts are generated. This is not a real-time delivery test. Only newly created conversation timestamps and memberships are backdated. Approved company/supervisor evidence is historical demo data without borrowing an existing admin reviewer.

Focused tests use transactions against `internflow_testing`, without running migrations, and roll back their data. The development seeder command above has not been executed.
