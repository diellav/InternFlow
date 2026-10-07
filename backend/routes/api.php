<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\RegistrationController;
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

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'active', 'role:ADMIN'])->group(function (): void {
    Route::post('/academic-coordinators', [AcademicCoordinatorController::class, 'store'])->name('coordinators.store');
    Route::patch('/academic-coordinators/{user}', [AcademicCoordinatorController::class, 'update'])
        ->whereNumber('user')->name('coordinators.update');
    Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
    Route::get('/users/{user}', [AdminUserController::class, 'show'])->whereNumber('user')->name('users.show');
    Route::patch('/users/{user}/activation', [AdminUserController::class, 'updateActivation'])
        ->whereNumber('user')->name('users.activation.update');
});
