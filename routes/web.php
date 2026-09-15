<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\FrontendShellController;
use App\Http\Controllers\ProfessorClassController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('landing');
});

// Auth Routes
Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/register', [AuthController::class, 'register'])->name('register');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Forgot Password Routes
Route::get('/forgot-password', [AuthController::class, 'showForgotPasswordForm'])->name('password.request');
Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])->name('password.email');

// Reset Password Routes
Route::get('/reset-password/{token}', [AuthController::class, 'showResetPasswordForm'])->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');

Route::middleware('auth')->group(function () {
    Route::get('/components/navbar/navbar.css', [FrontendShellController::class, 'navbarCss'])->name('shell.navbar.css');
    Route::get('/components/navbar/navbar.js', [FrontendShellController::class, 'navbarJs'])->name('shell.navbar.js');
    Route::get('/assets/{path}', [FrontendShellController::class, 'frontendAsset'])
        ->where('path', '.*');
});

Route::middleware(['auth', 'student.role'])->prefix('pages/student')->group(function () {
    Route::get('/{page}.html', [FrontendShellController::class, 'showStudentPage'])
        ->where('page', '[A-Za-z0-9\-]+');
});

Route::middleware(['auth', 'professor.role'])->prefix('pages/professor')->group(function () {
    Route::get('/{page}.html', [FrontendShellController::class, 'showProfessorPage'])
        ->where('page', '[A-Za-z0-9\-]+');
});

// Student Routes
Route::middleware(['auth', 'student.role'])->prefix('student')->name('student.')->group(function () {
    Route::get('/dashboard', [FrontendShellController::class, 'showShell'])->name('dashboard');
});

// Professor Routes
Route::middleware(['auth', 'professor.role'])->prefix('professor')->name('professor.')->group(function () {
    Route::get('/dashboard', [FrontendShellController::class, 'showShell'])->name('dashboard');

    Route::get('/classes', [ProfessorClassController::class, 'index'])->name('classes.index');
    Route::post('/classes', [ProfessorClassController::class, 'store'])->name('classes.store');
    Route::get('/classes/{class}', [ProfessorClassController::class, 'show'])->name('classes.show');
});
