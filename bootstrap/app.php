<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use App\Http\Middleware\EnsureStudentRole;
use App\Http\Middleware\EnsureProfessorRole;
use App\Http\Middleware\EnforceIdleLock;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'student.role' => EnsureStudentRole::class,
            'professor.role' => EnsureProfessorRole::class,
        ]);

        $middleware->web(append: EnforceIdleLock::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
