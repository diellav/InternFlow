<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\RegistrationController;
use App\Modules\Company\Controllers\AdminCompanyController;
use App\Modules\Company\Controllers\AdminSupervisorController;
use App\Modules\Company\Controllers\VerificationApplicationController;
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
