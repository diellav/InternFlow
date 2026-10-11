<?php

use App\Models\Company;
use App\Models\Internship;
use App\Models\Role;
use App\Models\User;
use App\Modules\Messaging\Services\MessagingService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || DB::selectOne('select current_database() as name')->name !== 'internflow_testing') {
    throw new RuntimeException('Browser fixtures require internflow_testing.');
}
$mode = $argv[1] ?? '';
if ($mode === 'create') {
    $fixture = DB::transaction(function (): array {
        $nonce = (string) Str::uuid();
        $createdRoles = [];
        foreach (['STUDENT', 'COMPANY_SUPERVISOR', 'ACADEMIC_COORDINATOR', 'ADMIN'] as $name) {
            $role = Role::firstOrCreate(['name' => $name]);
            if ($role->wasRecentlyCreated) {
                $createdRoles[] = $role->id;
            }
        }
        $company = Company::create(['name' => '9C isolated company '.$nonce, 'is_active' => true, 'verification_status' => 'APPROVED']);
        $users = [];
        foreach (['student' => 'STUDENT', 'supervisor' => 'COMPANY_SUPERVISOR', 'coordinator' => 'ACADEMIC_COORDINATOR', 'foreign' => 'COMPANY_SUPERVISOR', 'admin' => 'ADMIN'] as $name => $role) {
            $user = User::factory()->create(['email' => '9c-'.$name.'-'.$nonce.'@example.test', 'first_name' => ucfirst($name), 'last_name' => '9C Fixture',
                'password' => 'Test12345', 'role_id' => Role::where('name', $role)->sole()->id, 'is_active' => true]);
            match ($role) {
                'STUDENT' => $user->studentProfile()->create(['student_number' => $nonce, 'study_program' => 'CS', 'study_year' => 2]),
                'COMPANY_SUPERVISOR' => $user->companySupervisorProfile()->create(['company_id' => $company->id, 'verification_status' => 'APPROVED']),
                'ACADEMIC_COORDINATOR' => $user->academicCoordinatorProfile()->create(['academic_unit' => 'Engineering']),
                default => null,
            };
            $users[$name] = ['id' => $user->id, 'email' => $user->email];
        }
        $parent = Internship::create(['student_id' => $users['student']['id'], 'company_id' => $company->id, 'company_supervisor_id' => $users['supervisor']['id'],
            'coordinator_id' => $users['coordinator']['id'], 'position_title' => '9C browser internship', 'description' => 'Isolated verification only',
            'status' => 'ACTIVE', 'start_date' => '2026-10-01', 'end_date' => '2026-11-01']);
        $service = app(MessagingService::class);
        $student = User::findOrFail($users['student']['id']);
        $conversations = [];
        foreach (['student_supervisor', 'student_coordinator', 'group'] as $type) {
            $conversations[$type] = $service->open($student, $parent->id, $type)->id;
        }
        for ($i = 1; $i <= 30; $i++) {
            $service->send($student, $conversations['student_supervisor'], 'Fixture history '.$i);
        }

        return ['nonce' => $nonce, 'users' => $users, 'internship_id' => $parent->id, 'company_id' => $company->id,
            'conversations' => $conversations, 'created_roles' => $createdRoles];
    });
    echo json_encode($fixture, JSON_THROW_ON_ERROR);
    exit;
}
$fixture = json_decode(base64_decode($argv[2] ?? '', true) ?: '', true, 512, JSON_THROW_ON_ERROR);
$userIds = array_column($fixture['users'], 'id');
if (User::whereIn('id', $userIds)->where('email', 'like', '9c-%-'.$fixture['nonce'].'@example.test')->count() !== count($userIds)) {
    throw new RuntimeException('Fixture ownership cannot be verified.');
}
$parent = Internship::whereKey($fixture['internship_id'])->where('student_id', $fixture['users']['student']['id'])->where('company_id', $fixture['company_id'])->sole();
if ($mode === 'complete') {
    $parent->update(['status' => 'COMPLETED', 'completed_at' => now()]);
    echo 'Isolated fixture completed';
} elseif ($mode === 'summary') {
    echo json_encode(['messages' => DB::table('messages')->whereIn('conversation_id', $fixture['conversations'])->count(),
        'read_cursors' => DB::table('conversation_participants')->whereIn('conversation_id', $fixture['conversations'])->get(['conversation_id', 'user_id', 'last_read_message_id']),
        'notifications' => DB::table('notifications')->whereIn('notifiable_id', $userIds)->count()], JSON_THROW_ON_ERROR);
} elseif ($mode === 'cleanup') {
    DB::transaction(function () use ($fixture, $userIds, $parent): void {
        $ids = $parent->conversations()->pluck('id');
        DB::table('conversation_participants')->whereIn('conversation_id', $ids)->delete();
        DB::table('messages')->whereIn('conversation_id', $ids)->delete();
        DB::table('conversations')->whereIn('id', $ids)->delete();
        DB::table('notifications')->whereIn('notifiable_id', $userIds)->where('notifiable_type', (new User)->getMorphClass())->delete();
        $parent->delete();
        DB::table('student_profiles')->whereIn('user_id', $userIds)->delete();
        DB::table('company_supervisor_profiles')->whereIn('user_id', $userIds)->delete();
        DB::table('academic_coordinator_profiles')->whereIn('user_id', $userIds)->delete();
        User::whereIn('id', $userIds)->delete();
        Company::whereKey($fixture['company_id'])->delete();
        Role::whereIn('id', $fixture['created_roles'])->delete();
    });
    echo 'Only owned isolated browser fixtures removed';
} else {
    throw new RuntimeException('Unknown fixture operation.');
}
