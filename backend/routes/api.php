<?php

use App\Modules\Auth\Controllers\AuthController;
use App\Modules\Auth\Controllers\RegistrationController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register/student', [RegistrationController::class, 'student'])
    ->middleware('throttle:20,1')
    ->name('auth.register.student');

Route::post('/auth/register/supervisor', [RegistrationController::class, 'supervisor'])
    ->middleware('throttle:20,1')
    ->name('auth.register.supervisor');

Route::post('/auth/login', [AuthController::class, 'login'])
    ->middleware('throttle:login')
    ->name('auth.login');

Route::post('/auth/logout', [AuthController::class, 'logout'])
    ->middleware('auth:sanctum')
    ->name('auth.logout');

Route::get('/auth/me', [AuthController::class, 'me'])
    ->middleware(['auth:sanctum', 'active'])
    ->name('auth.me');
