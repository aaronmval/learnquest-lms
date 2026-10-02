<?php

use App\Http\Controllers\AI\ClassInsightController;
use App\Http\Controllers\AI\CompetencySuggestionController;
use App\Http\Controllers\AI\InsightController;
use App\Http\Controllers\AI\ProfessorQuestAiController;
use App\Http\Controllers\AI\QuestAiController;
use App\Http\Controllers\AI\QuizGenerationController;
use App\Http\Controllers\AI\SlideDeckController;
use App\Http\Controllers\AI\SummaryController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\ClassPostController;
use App\Http\Controllers\CompetencyController;
use App\Http\Controllers\FrontendShellController;
use App\Http\Controllers\ModuleController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OtpController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ProfessorClassController;
use App\Http\Controllers\ProfessorDashboardController;
use App\Http\Controllers\QuizAttemptController;
use App\Http\Controllers\QuizFeedbackController;
use App\Http\Controllers\QuizStudioController;
use App\Http\Controllers\StudentClassController;
use App\Http\Controllers\StudentClassPostController;
use App\Http\Controllers\StudentDashboardController;
use App\Http\Controllers\StudentMasteryController;
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
Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:6,1')->name('register');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Forgot Password Routes
Route::get('/forgot-password', [AuthController::class, 'showForgotPasswordForm'])->name('password.request');
Route::post('/forgot-password', [AuthController::class, 'sendResetLink'])
    ->middleware('throttle:6,1')->name('password.email');

// Email OTP Routes (new-account verification and password reset)
Route::get('/verify-otp', [OtpController::class, 'show'])->name('otp.show');
Route::post('/verify-otp', [OtpController::class, 'verify'])->middleware('throttle:6,1')->name('otp.verify');
Route::post('/verify-otp/resend', [OtpController::class, 'resend'])->middleware('throttle:6,1')->name('otp.resend');

// Reset Password Routes
Route::get('/reset-password/{token}', [AuthController::class, 'showResetPasswordForm'])->name('password.reset');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.update');

Route::middleware('auth')->group(function () {
    Route::get('/components/navbar/navbar.css', [FrontendShellController::class, 'navbarCss'])->name('shell.navbar.css');
    Route::get('/components/navbar/navbar.js', [FrontendShellController::class, 'navbarJs'])->name('shell.navbar.js');
    Route::get('/assets/{path}', [FrontendShellController::class, 'frontendAsset'])
        ->where('path', '.*');

    // System Alerts drawer (shared by every role)
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'markAllRead'])
        ->name('notifications.read-all');
    Route::post('/notifications/{id}/read', [NotificationController::class, 'markRead'])
        ->name('notifications.read');
    Route::delete('/notifications', [NotificationController::class, 'clear'])->name('notifications.clear');

    // Another user's profile photo, shown beside their name
    Route::get('/avatars/{user}', [SettingsController::class, 'showUserAvatar'])->name('avatars.show');

    // Account settings (shared by every role; always the signed-in user)
    Route::prefix('settings')->name('settings.')->group(function () {
        Route::get('/', [SettingsController::class, 'show'])->name('show');
        Route::put('/profile', [SettingsController::class, 'updateProfile'])->name('profile');
        Route::put('/notifications', [SettingsController::class, 'updateNotifications'])->name('notifications');
        Route::put('/general', [SettingsController::class, 'updateGeneral'])->name('general');
        Route::delete('/general', [SettingsController::class, 'resetGeneral'])->name('general.reset');
        Route::put('/password', [SettingsController::class, 'updatePassword'])
            ->middleware('throttle:6,1')->name('password');
        Route::delete('/account', [SettingsController::class, 'destroyAccount'])
            ->middleware('throttle:6,1')->name('account.destroy');
        Route::get('/avatar', [SettingsController::class, 'showAvatar'])->name('avatar.show');
        Route::post('/avatar', [SettingsController::class, 'uploadAvatar'])->name('avatar.upload');
        Route::delete('/avatar', [SettingsController::class, 'deleteAvatar'])->name('avatar.delete');
    });
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
    Route::get('/analytics', [StudentDashboardController::class, 'data'])->name('analytics');

    Route::get('/classes', [StudentClassController::class, 'index'])->name('classes.index');
    Route::get('/classes/lookup/{code}', [StudentClassController::class, 'lookup'])
        ->middleware('throttle:20,1')->name('classes.lookup');
    Route::post('/classes/join', [StudentClassController::class, 'join'])
        ->middleware('throttle:20,1')->name('classes.join');
    Route::get('/classes/{class}', [StudentClassController::class, 'show'])->name('classes.show');
    Route::get('/classes/{class}/mastery', [StudentMasteryController::class, 'forClass'])
        ->name('classes.mastery');
    Route::get('/classes/{class}/insights', [InsightController::class, 'show'])
        ->middleware('throttle:10,1')->name('classes.insights');

    Route::prefix('questai')->name('questai.')->group(function () {
        Route::get('/context', [QuestAiController::class, 'context'])->name('context');
        Route::get('/conversations', [QuestAiController::class, 'conversations'])->name('conversations.index');
        Route::get('/conversations/{conversation}', [QuestAiController::class, 'showConversation'])
            ->name('conversations.show');
        Route::post('/messages', [QuestAiController::class, 'storeMessage'])
            ->middleware('throttle:20,1')->name('messages.store');
        Route::post('/messages/{message}/feedback', [QuestAiController::class, 'feedback'])
            ->name('messages.feedback');
    });

    Route::get('/classes/{class}/posts', [StudentClassPostController::class, 'index'])
        ->name('classes.posts.index');
    Route::get('/classes/{class}/posts/{post}/attachment', [StudentClassPostController::class, 'attachment'])
        ->name('classes.posts.attachment');
    Route::get('/classes/{class}/posts/{post}/mastery', [StudentMasteryController::class, 'forPost'])
        ->name('classes.posts.mastery');
    Route::get('/classes/{class}/posts/{post}/summary', [SummaryController::class, 'show'])
        ->name('classes.posts.summary');
    Route::get('/classes/{class}/posts/{post}/quiz', [QuizGenerationController::class, 'show'])
        ->name('classes.posts.quiz.show');
    Route::post('/classes/{class}/posts/{post}/quiz/attempts', [QuizAttemptController::class, 'store'])
        ->name('classes.posts.quiz.attempts.store');
    Route::post('/classes/{class}/posts/{post}/quiz/attempts/{attempt}/feedback', [QuizFeedbackController::class, 'store'])
        ->name('classes.posts.quiz.attempts.feedback.store');
});

// Professor Routes
Route::middleware(['auth', 'professor.role'])->prefix('professor')->name('professor.')->group(function () {
    Route::get('/dashboard', [FrontendShellController::class, 'showShell'])->name('dashboard');
    Route::get('/analytics', [ProfessorDashboardController::class, 'data'])->name('analytics');
    Route::get('/analytics/insights', [ClassInsightController::class, 'show'])
        ->middleware('throttle:10,1')->name('analytics.insights');

    Route::prefix('questai')->name('questai.')->group(function () {
        Route::get('/context', [ProfessorQuestAiController::class, 'context'])->name('context');
        Route::get('/conversations', [ProfessorQuestAiController::class, 'conversations'])->name('conversations.index');
        Route::get('/conversations/{conversation}', [ProfessorQuestAiController::class, 'showConversation'])
            ->name('conversations.show');
        Route::post('/messages', [ProfessorQuestAiController::class, 'storeMessage'])
            ->middleware('throttle:20,1')->name('messages.store');
        Route::post('/messages/{message}/feedback', [ProfessorQuestAiController::class, 'feedback'])
            ->name('messages.feedback');

        Route::post('/decks', [SlideDeckController::class, 'store'])
            ->middleware('throttle:5,1')->name('decks.store');
        Route::get('/decks/{deck}/download', [SlideDeckController::class, 'download'])
            ->name('decks.download');
    });

    Route::get('/classes', [ProfessorClassController::class, 'index'])->name('classes.index');
    Route::post('/classes', [ProfessorClassController::class, 'store'])->name('classes.store');
    Route::get('/classes/archived', [ProfessorClassController::class, 'archived'])->name('classes.archived');
    Route::get('/classes/{class}', [ProfessorClassController::class, 'show'])->name('classes.show');
    Route::put('/classes/{class}', [ProfessorClassController::class, 'update'])->name('classes.update');
    Route::post('/classes/{class}/regenerate-code', [ProfessorClassController::class, 'regenerateCode'])
        ->name('classes.regenerate-code');
    Route::post('/classes/{class}/archive', [ProfessorClassController::class, 'archive'])->name('classes.archive');
    Route::post('/classes/{class}/restore', [ProfessorClassController::class, 'restore'])->name('classes.restore');
    Route::delete('/classes/{class}', [ProfessorClassController::class, 'destroy'])->name('classes.destroy');
    Route::get('/classes/{class}/students', [ProfessorClassController::class, 'students'])
        ->name('classes.students.index');
    Route::delete('/classes/{class}/students/{student}', [ProfessorClassController::class, 'removeStudent'])
        ->name('classes.students.destroy');

    Route::get('/classes/{class}/posts', [ClassPostController::class, 'index'])->name('classes.posts.index');
    Route::post('/classes/{class}/posts', [ClassPostController::class, 'store'])->name('classes.posts.store');
    Route::put('/classes/{class}/posts/{post}', [ClassPostController::class, 'update'])
        ->name('classes.posts.update'); // sent as POST with _method=PUT (multipart), spoofed to a real PUT for routing
    Route::delete('/classes/{class}/posts/{post}', [ClassPostController::class, 'destroy'])
        ->name('classes.posts.destroy');
    Route::get('/classes/{class}/posts/{post}/attachment', [ClassPostController::class, 'attachment'])
        ->name('classes.posts.attachment');
    Route::get('/classes/{class}/posts/{post}/quiz/feedback', [QuizGenerationController::class, 'feedback'])
        ->name('classes.posts.quiz.feedback');
    Route::post('/classes/{class}/posts/{post}/quiz/regenerate', [QuizGenerationController::class, 'regenerate'])
        ->name('classes.posts.quiz.regenerate');

    // Quiz & AI Setup page: settings, question review (AI training), metrics
    Route::get('/quiz-studio/lessons', [QuizStudioController::class, 'lessons'])->name('quiz-studio.lessons');
    Route::get('/quiz-studio/training', [QuizStudioController::class, 'training'])->name('quiz-studio.training');
    Route::prefix('/classes/{class}/posts/{post}/quiz')->name('classes.posts.quiz.')->group(function () {
        Route::get('/studio', [QuizStudioController::class, 'show'])->name('studio');
        Route::put('/settings', [QuizStudioController::class, 'updateSettings'])->name('settings');
        Route::post('/generate', [QuizStudioController::class, 'generate'])
            ->middleware('throttle:6,1')->name('generate');
        Route::post('/top-up', [QuizStudioController::class, 'topUp'])
            ->middleware('throttle:6,1')->name('top-up');
        Route::put('/questions/{question}/review', [QuizStudioController::class, 'review'])->name('questions.review');
        Route::put('/questions/{question}/competency', [QuizStudioController::class, 'updateCompetency'])
            ->name('questions.competency');
        Route::delete('/questions/{question}/review', [QuizStudioController::class, 'clearReview'])
            ->name('questions.review.clear');
    });

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
        Route::put('/{subject}/modules/{module}/sections', [ModuleController::class, 'updateSections'])
            ->name('modules.sections.update');
        Route::post('/{subject}/modules/{module}/change-requests', [ModuleController::class, 'requestChange'])
            ->middleware('throttle:10,1')->name('modules.change-requests.store');
        Route::get('/{subject}/modules/{module}/attachment', [ModuleController::class, 'attachment'])
            ->name('modules.attachment');

        Route::get('/{subject}/competencies', [CompetencyController::class, 'index'])->name('competencies.index');
        Route::post('/{subject}/competencies', [CompetencyController::class, 'store'])->name('competencies.store');
        Route::post('/{subject}/competencies/suggest', [CompetencySuggestionController::class, 'store'])
            ->middleware('throttle:6,1')->name('competencies.suggest');
        Route::put('/{subject}/competencies/{competency}', [CompetencyController::class, 'update'])
            ->name('competencies.update');
        Route::delete('/{subject}/competencies/{competency}', [CompetencyController::class, 'destroy'])
            ->name('competencies.destroy');
    });
});
