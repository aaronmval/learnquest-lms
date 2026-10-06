<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyOtpRequest;
use App\Models\User;
use App\Services\Auth\IdleLockService;
use App\Services\Auth\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Unlocking a session locked by the Settings → Security idle lock
 * (EnforceIdleLock). The shell shows its own overlay and talks to these
 * routes as JSON; a page load while locked gets the full-page screen.
 * Codes always go to the signed-in user's own email.
 */
class SessionLockController extends Controller
{
    private const PURPOSE = OtpService::PURPOSE_SESSION_UNLOCK;

    public function __construct(
        private IdleLockService $idleLock,
        private OtpService $otp,
    ) {
    }

    /** Full-page lock screen. */
    public function show(Request $request): View|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $this->idleLock->isLocked($request->session())) {
            return redirect()->route($this->homeRoute($user));
        }

        $sendError = $this->sendCodeOnce($request);
        $attemptsLeft = $this->otp->attemptsRemaining($user->email, self::PURPOSE);

        $view = view('auth.otp-verification', [
            'heading' => 'Session Locked',
            'verifyAction' => route('session-lock.verify'),
            'resendAction' => route('session-lock.resend'),
            'signOut' => true,
            'email' => $this->maskEmail($user->email),
            'length' => (int) config('otp.length'),
            'attemptsLeft' => $attemptsLeft,
            'maxAttempts' => (int) config('otp.max_attempts'),
            'locked' => $attemptsLeft === 0,
            'resendIn' => $this->otp->secondsUntilResend($user->email, self::PURPOSE),
        ]);

        // Only when sending failed; otherwise keep any error flashed by verify().
        return $sendError !== null ? $view->withErrors(['code' => $sendError]) : $view;
    }

    /** The shell's idle timer ran out: lock and send a code. */
    public function lock(Request $request): JsonResponse
    {
        $this->idleLock->lock($request->session());
        $sendError = $this->sendCodeOnce($request);

        return response()->json($this->state($request) + ['error' => $sendError]);
    }

    public function verify(VerifyOtpRequest $request): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $session = $request->session();

        if (! $this->idleLock->isLocked($session)) {
            return $request->expectsJson()
                ? response()->json(['locked' => false])
                : redirect()->route($this->homeRoute($user));
        }

        $result = $this->otp->verify($user->email, self::PURPOSE, $request->validated()['code']);

        if ($result !== OtpService::VERIFY_OK) {
            $message = match ($result) {
                OtpService::VERIFY_EXPIRED => 'This code has expired. Please request a new one.',
                OtpService::VERIFY_LOCKED => 'Too many failed attempts. Please request a new code to continue.',
                default => 'Invalid code. Please try again.',
            };

            return $request->expectsJson()
                ? response()->json(['message' => $message, 'errors' => ['code' => [$message]]] + $this->state($request), 422)
                : back()->withErrors(['code' => $message]);
        }

        $this->idleLock->unlock($session);
        $session->regenerate();

        return $request->expectsJson()
            ? response()->json(['locked' => false])
            : redirect()->route($this->homeRoute($user));
    }

    public function resend(Request $request): JsonResponse|RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $message = match ($this->otp->issue($user->email, self::PURPOSE, $user)) {
            OtpService::ISSUE_COOLDOWN => 'Please wait before requesting another code.',
            OtpService::ISSUE_FAILED => 'We could not send the email right now. Please try again.',
            default => null,
        };

        if ($request->expectsJson()) {
            return response()->json($this->state($request) + ['error' => $message], $message === null ? 200 : 422);
        }

        return $message === null
            ? back()->with('status', 'A new code has been sent to your email!')
            : back()->withErrors(['code' => $message]);
    }

    /** Polled in the background by every open tab, so they all lock together. */
    public function status(Request $request): JsonResponse
    {
        return response()->json([
            'locked' => $this->idleLock->isLocked($request->session()),
            'idle_lock_minutes' => (int) $request->user()->idle_lock_minutes,
        ]);
    }

    /** Activity in the shell (EnforceIdleLock has already recorded it). */
    public function heartbeat(): JsonResponse
    {
        return response()->json(['locked' => false]);
    }

    /**
     * Email a code the first time a lock needs one; later sends go through
     * Resend so its cooldown applies. Returns an error message, or null.
     */
    private function sendCodeOnce(Request $request): ?string
    {
        $session = $request->session();

        if ($this->idleLock->codeSent($session)) {
            return null;
        }

        /** @var User $user */
        $user = $request->user();
        $result = $this->otp->issue($user->email, self::PURPOSE, $user);

        if ($result === OtpService::ISSUE_FAILED) {
            return 'We could not send the email right now. Use Resend to try again.';
        }

        $this->idleLock->markCodeSent($session);

        return null;
    }

    /** @return array<string, mixed> */
    private function state(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();

        return [
            'locked' => $this->idleLock->isLocked($request->session()),
            'email' => $this->maskEmail($user->email),
            'length' => (int) config('otp.length'),
            'attempts_left' => $this->otp->attemptsRemaining($user->email, self::PURPOSE),
            'resend_in' => $this->otp->secondsUntilResend($user->email, self::PURPOSE),
        ];
    }

    /** "maria@example.com" → "ma***@example.com" */
    private function maskEmail(string $email): string
    {
        [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');

        return mb_substr($local, 0, 2).'***'.($domain !== '' ? '@'.$domain : '');
    }

    private function homeRoute(User $user): string
    {
        return $user->role === 'professor' ? 'professor.dashboard' : 'student.dashboard';
    }
}
