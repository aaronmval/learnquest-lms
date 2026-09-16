<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassPostController;
use App\Http\Controllers\FrontendShellController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\ProfessorClassController;
use App\Http\Controllers\StudentClassController;
use App\Http\Controllers\StudentClassPostController;
use App\Http\Controllers\SubjectCollaboratorController;
use App\Http\Controllers\SubjectController;
use App\Http\Controllers\SubjectSectionController;
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

    Route::get('/classes/{class}/posts', [StudentClassPostController::class, 'index'])
        ->name('classes.posts.index');
    Route::get('/classes/{class}/posts/{post}/attachment', [StudentClassPostController::class, 'attachment'])
        ->name('classes.posts.attachment');
});

// Professor Routes
Route::middleware(['auth', 'professor.role'])->prefix('professor')->name('professor.')->group(function () {
    Route::get('/dashboard', [FrontendShellController::class, 'showShell'])->name('dashboard');

    Route::get('/classes', [ProfessorClassController::class, 'index'])->name('classes.index');
    Route::post('/classes', [ProfessorClassController::class, 'store'])->name('classes.store');
    Route::get('/classes/archived', [ProfessorClassController::class, 'archived'])->name('classes.archived');
    Route::get('/classes/{class}', [ProfessorClassController::class, 'show'])->name('classes.show');
    Route::post('/classes/{class}/regenerate-code', [ProfessorClassController::class, 'regenerateCode'])
        ->name('classes.regenerate-code');
    Route::post('/classes/{class}/archive', [ProfessorClassController::class, 'archive'])->name('classes.archive');
    Route::post('/classes/{class}/restore', [ProfessorClassController::class, 'restore'])->name('classes.restore');
    Route::delete('/classes/{class}', [ProfessorClassController::class, 'destroy'])->name('classes.destroy');

    Route::get('/classes/{class}/posts', [ClassPostController::class, 'index'])->name('classes.posts.index');
    Route::post('/classes/{class}/posts', [ClassPostController::class, 'store'])->name('classes.posts.store');
    Route::post('/classes/{class}/posts/{post}', [ClassPostController::class, 'update'])
        ->name('classes.posts.update'); // _method=PUT spoofed (multipart)
    Route::delete('/classes/{class}/posts/{post}', [ClassPostController::class, 'destroy'])
        ->name('classes.posts.destroy');
    Route::get('/classes/{class}/posts/{post}/attachment', [ClassPostController::class, 'attachment'])
        ->name('classes.posts.attachment');

    Route::prefix('subjects')->name('subjects.')->group(function () {
        Route::get('/', [SubjectController::class, 'index'])->name('index');
        Route::post('/', [SubjectController::class, 'store'])->name('store');
        Route::get('/{subject}', [SubjectController::class, 'show'])->name('show');
        Route::put('/{subject}', [SubjectController::class, 'update'])->name('update');

        Route::post('/{subject}/sections', [SubjectSectionController::class, 'store'])
            ->name('sections.store');

        Route::post('/{subject}/collaborators', [SubjectCollaboratorController::class, 'store'])
            ->name('collaborators.store');
        Route::delete('/{subject}/collaborators/{user}', [SubjectCollaboratorController::class, 'destroy'])
            ->name('collaborators.destroy');

        Route::get('/{subject}/modules', [ModuleController::class, 'index'])->name('modules.index');
        Route::post('/{subject}/modules', [ModuleController::class, 'store'])->name('modules.store');
        Route::put('/{subject}/modules/{module}', [ModuleController::class, 'update'])
            ->name('modules.update'); // sent as POST with _method=PUT (multipart), spoofed to a real PUT for routing
        Route::delete('/{subject}/modules/{module}', [ModuleController::class, 'destroy'])
            ->name('modules.destroy');
        Route::get('/{subject}/modules/{module}/attachment', [ModuleController::class, 'attachment'])
            ->name('modules.attachment');
    });
});
