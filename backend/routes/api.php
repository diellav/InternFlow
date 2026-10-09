<?php

use App\Modules\Activity\Controllers\StudentActivityController;
use App\Modules\Activity\Controllers\SupervisorActivityController;
use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\RegistrationController;
use App\Modules\Company\Controllers\AdminCompanyController;
use App\Modules\Company\Controllers\AdminSupervisorController;
use App\Modules\Company\Controllers\VerificationApplicationController;
use App\Modules\Internship\Controllers\CoordinatorInternshipController;
use App\Modules\Internship\Controllers\StudentInternshipController;
use App\Modules\Internship\Controllers\SupervisorInternshipController;
use App\Modules\Monitoring\Controllers\CoordinatorMonitoringController;
use App\Modules\Task\Controllers\StudentTaskController;
use App\Modules\Task\Controllers\TaskController;
use App\Modules\Task\Controllers\TaskReviewController;
use App\Modules\Task\Controllers\TaskSubmissionFileController;
use App\Modules\User\Controllers\AcademicCoordinatorController;
use App\Modules\User\Controllers\AdminUserController;
use App\Modules\User\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register/student', [RegistrationController::class, 'student'])
    ->middleware('throttle:20,1')
    ->name('auth.register.student');

Route::post('/auth/register/supervisor', [RegistrationController::class, 'supervisor'])
    ->middleware('throttle:20,1')
    ->name('auth.register.supervisor');

Route::get('/auth/register/supervisor/companies', [RegistrationController::class, 'companies'])
    ->middleware('throttle:60,1')
    ->name('auth.register.supervisor.companies');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');

Route::post('/auth/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum')
    ->name('auth.logout');

Route::get('/auth/me', [AuthController::class, 'me'])
    ->middleware(['auth:sanctum', 'active'])
    ->name('auth.me');

Route::middleware(['auth:sanctum', 'active'])->group(function (): void {
    Route::get('/task-submission-files/{file}/download', [TaskSubmissionFileController::class, 'download'])->whereNumber('file')->name('task-submission-files.download');
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::prefix('supervisor/verification-application')->middleware(['auth:sanctum', 'active', 'role:COMPANY_SUPERVISOR'])->group(function (): void {
    Route::get('/', [VerificationApplicationController::class, 'show'])->name('supervisor.application.show');
    Route::patch('/', [VerificationApplicationController::class, 'update'])->name('supervisor.application.update');
    Route::post('/resubmit', [VerificationApplicationController::class, 'resubmit'])->name('supervisor.application.resubmit');
});

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'active', 'role:ADMIN'])->group(function (): void {
    Route::get('/supervisors', [AdminSupervisorController::class, 'index'])->name('supervisors.index');
    Route::get('/supervisors/{user}', [AdminSupervisorController::class, 'show'])->whereNumber('user')->name('supervisors.show');
    Route::patch('/supervisors/{user}/verification', [AdminSupervisorController::class, 'verify'])->whereNumber('user')->name('supervisors.verification');
    Route::get('/companies', [AdminCompanyController::class, 'index'])->name('companies.index');
    Route::get('/companies/{company}', [AdminCompanyController::class, 'show'])
        ->whereNumber('company')->name('companies.show');
    Route::patch('/companies/{company}/verification', [AdminCompanyController::class, 'verify'])
        ->whereNumber('company')->name('companies.verification');
    Route::post('/academic-coordinators', [AcademicCoordinatorController::class, 'store'])->name('coordinators.store');
    Route::patch('/academic-coordinators/{user}', [AcademicCoordinatorController::class, 'update'])
        ->whereNumber('user')->name('coordinators.update');
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->whereNumber('user')->name('users.show');
    Route::patch('/users/{user}/activation', [AdminUserController::class, 'updateActivation'])
        ->whereNumber('user')->name('users.activation.update');
});

Route::prefix('student')->name('student.')->middleware(['auth:sanctum', 'active', 'role:STUDENT'])->group(function (): void {
    Route::get('/internships/{internship}/activities', [StudentActivityController::class, 'index'])->whereNumber('internship')->name('activities.index');
    Route::post('/internships/{internship}/activities', [StudentActivityController::class, 'store'])->whereNumber('internship')->name('activities.store');
    Route::get('/activities/{activity}', [StudentActivityController::class, 'show'])->whereNumber('activity')->name('activities.show');
    Route::patch('/activities/{activity}', [StudentActivityController::class, 'update'])->whereNumber('activity')->name('activities.update');
    Route::post('/tasks/{task}/resubmit', [StudentTaskController::class, 'resubmit'])->whereNumber('task')->name('tasks.resubmit');
    Route::post('/tasks/{task}/start', [StudentTaskController::class, 'start'])->whereNumber('task')->name('tasks.start');
    Route::post('/tasks/{task}/submissions', [StudentTaskController::class, 'submit'])->whereNumber('task')->name('tasks.submit');
    Route::get('/tasks/{task}/submissions', [StudentTaskController::class, 'submissions'])->whereNumber('task')->name('tasks.submissions');
    Route::get('/internships/{internship}/tasks', [TaskController::class, 'studentIndex'])->whereNumber('internship')->name('tasks.index');
    Route::get('/tasks/{task}', [TaskController::class, 'studentShow'])->whereNumber('task')->name('tasks.show');
    Route::get('/internship-options/companies', [StudentInternshipController::class, 'companies'])->name('internship-options.companies');
    Route::get('/internship-options/companies/{company}/supervisors', [StudentInternshipController::class, 'supervisors'])->whereNumber('company')->name('internship-options.supervisors');
    Route::get('/internships', [StudentInternshipController::class, 'index'])->name('internships.index');
    Route::post('/internships', [StudentInternshipController::class, 'store'])->name('internships.store');
    Route::post('/internships/{internship}/submit', [StudentInternshipController::class, 'submit'])->whereNumber('internship')->name('internships.submit');
    Route::post('/internships/{internship}/resubmit', [StudentInternshipController::class, 'resubmit'])->whereNumber('internship')->name('internships.resubmit');
    Route::get('/internships/{internship}', [StudentInternshipController::class, 'show'])->whereNumber('internship')->name('internships.show');
    Route::patch('/internships/{internship}', [StudentInternshipController::class, 'update'])->whereNumber('internship')->name('internships.update');
});

Route::prefix('coordinator')->name('coordinator.')->middleware(['auth:sanctum', 'active', 'role:ACADEMIC_COORDINATOR'])->group(function (): void {
    Route::prefix('monitoring')->name('monitoring.')->group(function (): void {
        Route::get('/internships', [CoordinatorMonitoringController::class, 'index'])->name('internships.index');
        Route::get('/internships/{internship}', [CoordinatorMonitoringController::class, 'show'])->whereNumber('internship')->name('internships.show');
        Route::get('/internships/{internship}/activities', [CoordinatorMonitoringController::class, 'activities'])->whereNumber('internship')->name('activities.index');
        Route::get('/activities/{activity}', [CoordinatorMonitoringController::class, 'activity'])->whereNumber('activity')->name('activities.show');
        Route::get('/internships/{internship}/tasks', [CoordinatorMonitoringController::class, 'tasks'])->whereNumber('internship')->name('tasks.index');
        Route::get('/tasks/{task}', [CoordinatorMonitoringController::class, 'task'])->whereNumber('task')->name('tasks.show');
        Route::get('/tasks/{task}/submissions', [CoordinatorMonitoringController::class, 'submissions'])->whereNumber('task')->name('submissions.index');
    });
    Route::get('/internships', [CoordinatorInternshipController::class, 'index'])->name('internships.index');
    Route::get('/internships/{internship}', [CoordinatorInternshipController::class, 'show'])->whereNumber('internship')->name('internships.show');
    Route::post('/internships/{internship}/claim', [CoordinatorInternshipController::class, 'claim'])->whereNumber('internship')->name('internships.claim');
    Route::post('/internships/{internship}/start-review', [CoordinatorInternshipController::class, 'startReview'])->whereNumber('internship')->name('internships.start-review');
    Route::post('/internships/{internship}/decision', [CoordinatorInternshipController::class, 'decision'])->whereNumber('internship')->name('internships.decision');
});

Route::prefix('supervisor')->name('supervisor.')->middleware(['auth:sanctum', 'active', 'role:COMPANY_SUPERVISOR', 'supervisor.approved'])->group(function (): void {
    Route::get('/internships/{internship}/activities', [SupervisorActivityController::class, 'index'])->whereNumber('internship')->name('activities.index');
    Route::get('/activities/{activity}', [SupervisorActivityController::class, 'show'])->whereNumber('activity')->name('activities.show');
    Route::post('/tasks/{task}/review', [TaskReviewController::class, 'store'])->whereNumber('task')->name('tasks.review');
    Route::get('/tasks/{task}/submissions', [StudentTaskController::class, 'submissions'])->whereNumber('task')->name('tasks.submissions');
    Route::get('/internships', [SupervisorInternshipController::class, 'index'])->name('internships.index');
    Route::get('/internships/{internship}', [SupervisorInternshipController::class, 'show'])->whereNumber('internship')->name('internships.show');
    Route::post('/internships/{internship}/activate', [SupervisorInternshipController::class, 'activate'])->whereNumber('internship')->name('internships.activate');
    Route::get('/internships/{internship}/tasks', [TaskController::class, 'supervisorIndex'])->whereNumber('internship')->name('tasks.index');
    Route::post('/internships/{internship}/tasks', [TaskController::class, 'store'])->whereNumber('internship')->name('tasks.store');
    Route::get('/tasks/{task}', [TaskController::class, 'supervisorShow'])->whereNumber('task')->name('tasks.show');
    Route::patch('/tasks/{task}', [TaskController::class, 'update'])->whereNumber('task')->name('tasks.update');
});
