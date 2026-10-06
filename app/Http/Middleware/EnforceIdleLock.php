<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\IdleLockService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Settings → Security idle lock. Once a signed-in user has been idle longer
 * than their chosen limit, every request except the unlock routes and
 * sign-out is refused until they enter the code emailed to them.
 *
 * Requests sent with "X-LQ-Background: 1" (automatic polling) don't count
 * as activity, so an open tab nobody touches still locks.
 */
class EnforceIdleLock
{
    /** Routes that must work while locked. */
    private const ALWAYS_ALLOWED = [
        'session-lock.show',
        'session-lock.lock',
        'session-lock.verify',
        'session-lock.resend',
        'session-lock.status',
        'logout',
    ];

    public function __construct(private IdleLockService $idleLock)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $this->idleLock->watches($user)) {
            return $next($request);
        }

        $session = $request->session();
        $this->idleLock->lockIfIdle($user, $session, Auth::viaRemember());

        if ($request->routeIs(...self::ALWAYS_ALLOWED)) {
            return $next($request);
        }

        if ($this->idleLock->isLocked($session)) {
            return $request->expectsJson()
                ? response()->json(['locked' => true, 'message' => 'Your session is locked.'], 423)
                : redirect()->route('session-lock.show');
        }

        if ($request->header('X-LQ-Background') !== '1') {
            $this->idleLock->touch($session);
        }

        return $next($request);
    }
}
