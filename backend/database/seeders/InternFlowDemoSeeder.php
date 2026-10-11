<?php

namespace Database\Seeders;

use App\Models\AcademicCoordinatorProfile;
use App\Models\ActivityLog;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\CompanySupervisorProfile;
use App\Models\FinalEvaluation;
use App\Models\Internship;
use App\Models\Message;
use App\Models\Role;
use App\Models\StudentProfile;
use App\Models\Task;
use App\Models\TaskFeedback;
use App\Models\TaskSubmission;
use App\Models\User;
use App\Modules\Messaging\Services\MessagingService;
use App\Shared\Enums\InternshipStatus;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

class InternFlowDemoSeeder extends Seeder
{
    public const MARKER = 'internflow.demo.seed.v1';

    public function run(): void
    {
        if (! app()->environment(['local', 'testing']) || DB::getDriverName() !== 'pgsql') {
            throw new RuntimeException('Demo seeding requires a local/testing PostgreSQL environment.');
        }

        DB::transaction(function (): void {
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [self::MARKER]);
            if (AuditLog::where('action', self::MARKER)->exists()) {
                $this->command?->info('Demo dataset already seeded; no records changed.');

                return;
            }

            $names = [['Arta', 'Krasniqi'], ['Leon', 'Berisha'], ['Era', 'Gashi'], ['Dion', 'Hoxha'], ['Sara', 'Shala'],
                ['Blerta', 'Morina'], ['Valon', 'Kelmendi'], ['Gentian', 'Dervishi'], ['Elira', 'Mehmeti'], ['Arben', 'Bytyqi']];
            $companies = ['NovaTech Solutions', 'CodeBridge Kosovo', 'Digital Horizon'];
            $roles = Role::whereIn('name', ['STUDENT', 'ACADEMIC_COORDINATOR', 'COMPANY_SUPERVISOR'])->pluck('id', 'name');
            if ($roles->count() !== 3) {
                throw new RuntimeException('Required existing roles are missing; demo seeding stopped.');
            }
            foreach ($names as $index => [$first, $last]) {
                $email = strtolower($first.'.'.$last).'@test.com';
                if (User::whereRaw('LOWER(email) = ?', [$email])->exists()
                    || ($index < 5 && StudentProfile::where('student_number', 'IF-DEMO-'.($index + 1))->exists())) {
                    throw new RuntimeException('Demo identifier collision: '.$email.'; no records changed.');
                }
            }
            foreach ($companies as $name) {
                if (Company::whereRaw('LOWER(name) = ?', [strtolower($name)])->exists()) {
                    throw new RuntimeException('Demo company collision: '.$name.'; no records changed.');
                }
            }

            $today = today(config('app.timezone'));
            $createdCompanies = [];
            foreach ($companies as $index => $name) {
                $createdCompanies[] = Company::create(['name' => $name, 'industry' => 'Teknologji informative',
                    'address' => 'Rruga Demo '.($index + 1).', Prishtinë', 'website' => 'https://company'.($index + 1).'.example',
                    'is_active' => true, 'verification_status' => 'APPROVED', 'verified_at' => $today->copy()->subDays(60),
                    'verification_note' => 'Kompani fiktive për demonstrim; zhvillim aplikacionesh web.']);
            }
            $users = [];
            foreach ($names as $index => [$first, $last]) {
                $role = $index < 5 ? 'STUDENT' : ($index < 7 ? 'ACADEMIC_COORDINATOR' : 'COMPANY_SUPERVISOR');
                $user = User::create(['first_name' => $first, 'last_name' => $last, 'email' => strtolower($first.'.'.$last).'@test.com',
                    'role_id' => $roles[$role], 'password' => Hash::make('Test12345'), 'is_active' => true]);
                $users[] = $user;
                if ($index < 5) {
                    StudentProfile::create(['user_id' => $user->id, 'student_number' => 'IF-DEMO-'.($index + 1), 'study_program' => 'Shkenca Kompjuterike', 'study_year' => 3]);
                } elseif ($index < 7) {
                    AcademicCoordinatorProfile::create(['user_id' => $user->id, 'academic_unit' => 'Fakulteti i Shkencave Kompjuterike']);
                } else {
                    CompanySupervisorProfile::create(['user_id' => $user->id, 'company_id' => $createdCompanies[$index - 7]->id,
                        'job_title' => 'Senior Software Engineer', 'verification_status' => 'APPROVED',
                        'reviewed_at' => $today->copy()->subDays(60), 'review_comment' => 'Profil fiktiv për demonstrim.']);
                }
            }

            $specs = [
                [0, 0, InternshipStatus::SUBMITTED, 'Frontend Development Internship'],
                [1, 1, InternshipStatus::REJECTED, 'Backend API Development'],
                [1, 1, InternshipStatus::APPROVED, 'QA and Software Testing'],
                [2, 2, InternshipStatus::APPROVED, 'Database Development'],
                [3, 0, InternshipStatus::ACTIVE, 'Full-Stack Web Development'],
                [4, 1, InternshipStatus::ACTIVE, 'UI/UX Implementation'],
                [0, 2, InternshipStatus::COMPLETED, 'Software Engineering Internship'],
            ];
            $internships = [];
            foreach ($specs as $index => [$student, $company, $status, $title]) {
                $working = in_array($status, [InternshipStatus::ACTIVE, InternshipStatus::COMPLETED], true);
                $approved = $working || $status === InternshipStatus::APPROVED;
                $internship = Internship::forceCreate(['student_id' => $users[$student]->id, 'company_id' => $createdCompanies[$company]->id,
                    'company_supervisor_id' => $users[7 + $company]->id, 'coordinator_id' => $status === InternshipStatus::SUBMITTED ? null : $users[5 + ($company % 2)]->id,
                    'position_title' => $title, 'description' => 'Praktikë fiktive për zhvillim, testim dhe dokumentim të aplikacioneve web.',
                    'start_date' => $today->copy()->addDays($working ? -30 : 7),
                    'end_date' => $today->copy()->addDays($status === InternshipStatus::COMPLETED ? -1 : 30),
                    'status' => $status->value, 'created_at' => $today->copy()->subDays(46), 'submitted_at' => $today->copy()->subDays(45),
                    'approved_at' => $approved ? $today->copy()->subDays(40) : null,
                    'completed_at' => $status === InternshipStatus::COMPLETED ? $today->copy()->subHour() : null,
                    'decision_comment' => $status === InternshipStatus::REJECTED ? 'Plani i praktikës duhet të përmbajë objektiva më të qarta.' : null]);
                $internships[] = $internship;
                if (! $working) {
                    continue;
                }
                $taskStates = $status === InternshipStatus::COMPLETED ? ['APPROVED', 'APPROVED'] : ['ASSIGNED', 'IN_PROGRESS', 'SUBMITTED', 'APPROVED'];
                foreach ($taskStates as $taskIndex => $taskStatus) {
                    $task = Task::forceCreate(['internship_id' => $internship->id, 'assigned_by' => $internship->company_supervisor_id,
                        'title' => ['Build a responsive login page', 'Implement REST API endpoints', 'Write unit tests', 'Document API functionality'][$taskIndex],
                        'description' => 'Zbatoni dhe dokumentoni kërkesat e dakorduara me mbikëqyrësin.', 'priority' => 'MEDIUM',
                        'due_date' => $today->copy()->subDays(2), 'status' => $taskStatus,
                        'progress_percent' => match ($taskStatus) {
                            'ASSIGNED' => 0, 'IN_PROGRESS' => 50, default => 100
                        },
                        'created_at' => $today->copy()->subDays(20)]);
                    if (in_array($taskStatus, ['SUBMITTED', 'APPROVED'], true)) {
                        $submission = TaskSubmission::create(['task_id' => $task->id, 'version_no' => 1,
                            'submission_text' => 'Zbatimi dhe testimi janë përfunduar; dokumentacioni përfshin rezultatet.', 'submitted_at' => $today->copy()->subDays(3)]);
                        if ($taskStatus === 'APPROVED') {
                            TaskFeedback::forceCreate(['submission_id' => $submission->id, 'supervisor_id' => $internship->company_supervisor_id,
                                'decision' => 'APPROVED', 'comment' => 'Punë e mirë; zbatimi plotëson kërkesat.', 'created_at' => $today->copy()->subDays(2)]);
                        }
                    }
                }
                foreach ([4, 3, 5] as $day => $hours) {
                    ActivityLog::forceCreate(['internship_id' => $internship->id, 'activity_date' => $today->copy()->subDays(10 - $day),
                        'title' => ['Implemented login form', 'Tested API endpoints', 'Developed dashboard UI'][$day],
                        'description' => 'Aktivitet fiktiv i dokumentuar për demonstrimin e progresit.', 'hours' => $hours,
                        'created_at' => $today->copy()->subDays(10 - $day)]);
                }
                if ($status === InternshipStatus::COMPLETED) {
                    FinalEvaluation::create(['internship_id' => $internship->id, 'evaluator_id' => $internship->company_supervisor_id,
                        ...array_fill_keys(FinalEvaluation::RATINGS, 4), 'comments' => 'Studenti realizoi objektivat e praktikës dhe bashkëpunoi me profesionalizëm.',
                        'submitted_at' => $today->copy()->subHours(2)]);
                } else {
                    $conversation = app(MessagingService::class)->open($users[$student], $internship->id, 'group');
                    $historicalStart = $today->copy()->subDays(5);
                    DB::table('conversations')->where('id', $conversation->id)->update(['created_at' => $historicalStart]);
                    DB::table('conversation_participants')->where('conversation_id', $conversation->id)->update(['joined_at' => $historicalStart]);
                    $senders = [$internship->student_id, $internship->company_supervisor_id, $internship->coordinator_id];
                    $texts = ['Përshëndetje, e kam përfunduar detyrën e parë.', 'Faleminderit, do ta kontrolloj sot.', 'Ju lutem vazhdoni me aktivitetet sipas planit.',
                        'Sot do të testoj formularin dhe validimin.', 'Kontrolloni edhe paraqitjen në telefon.', 'Mos harroni të regjistroni orët e punës.',
                        'Testet kaluan dhe dokumentacioni është përditësuar.', 'Punë e mirë, vazhdoni me detyrën tjetër.', 'Faleminderit për përditësimin e progresit.'];
                    foreach ($texts as $messageIndex => $content) {
                        Message::create(['conversation_id' => $conversation->id, 'sender_id' => $senders[$messageIndex % 3],
                            'content' => $content, 'sent_at' => $historicalStart->copy()->addMinutes($messageIndex + 1)]);
                    }
                }
            }
            AuditLog::create(['user_id' => null, 'action' => self::MARKER, 'entity_type' => 'demo_dataset', 'entity_id' => 1,
                'new_values' => ['users' => array_map(fn ($user) => $user->id, $users),
                    'companies' => array_map(fn ($company) => $company->id, $createdCompanies), 'internships' => array_map(fn ($internship) => $internship->id, $internships)]]);
            $this->command?->info('Demo dataset added: 10 users, 3 companies, 7 internships, 18 historical messages.');
        });
    }
}
