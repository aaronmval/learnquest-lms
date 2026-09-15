<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassPostController;
use App\Http\Controllers\FrontendShellController;
use App\Http\Controllers\ProfessorClassController;
use App\Http\Controllers\StudentClassController;
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

    Route::get('/classes', [StudentClassController::class, 'index'])->name('classes.index');
    Route::get('/classes/lookup/{code}', [StudentClassController::class, 'lookup'])
        ->middleware('throttle:20,1')->name('classes.lookup');
    Route::post('/classes/join', [StudentClassController::class, 'join'])
        ->middleware('throttle:20,1')->name('classes.join');
    Route::get('/classes/{class}', [StudentClassController::class, 'show'])->name('classes.show');
});

// Professor Routes
Route::middleware(['auth', 'professor.role'])->prefix('professor')->name('professor.')->group(function () {
    Route::get('/dashboard', [FrontendShellController::class, 'showShell'])->name('dashboard');

    Route::get('/classes', [ProfessorClassController::class, 'index'])->name('classes.index');
    Route::post('/classes', [ProfessorClassController::class, 'store'])->name('classes.store');
    Route::get('/classes/{class}', [ProfessorClassController::class, 'show'])->name('classes.show');
    Route::post('/classes/{class}/regenerate-code', [ProfessorClassController::class, 'regenerateCode'])
        ->name('classes.regenerate-code');

    Route::get('/classes/{class}/posts', [ClassPostController::class, 'index'])->name('classes.posts.index');
    Route::post('/classes/{class}/posts', [ClassPostController::class, 'store'])->name('classes.posts.store');
    Route::post('/classes/{class}/posts/{post}', [ClassPostController::class, 'update'])
        ->name('classes.posts.update'); // _method=PUT spoofed (multipart)
    Route::delete('/classes/{class}/posts/{post}', [ClassPostController::class, 'destroy'])
        ->name('classes.posts.destroy');
    Route::get('/classes/{class}/posts/{post}/attachment', [ClassPostController::class, 'attachment'])
        ->name('classes.posts.attachment');
});
