<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * Settings → Security idle lock. After the user's chosen number of idle
 * minutes the session is marked locked (the user stays signed in) until
 * they enter a code emailed to them. State lives in the session, so other
 * devices and browsers are unaffected.
 */
class IdleLockService
{
    private const LAST_ACTIVITY = 'idle.last_activity';
    private const LOCKED = 'idle.locked';
    private const CODE_SENT = 'idle.code_sent';

    public function enabled(User $user): bool
    {
        return (int) $user->idle_lock_minutes > 0;
    }

    /**
     * Whether this user's sessions need watching at all: the idle lock is on,
     * or they want a code on every sign-in (which a "Remember me" cookie
     * would otherwise skip).
     */
    public function watches(User $user): bool
    {
        return $this->enabled($user) || (bool) $user->otp_on_login;
    }

    public function isLocked(Session $session): bool
    {
        return (bool) $session->get(self::LOCKED, false);
    }

    /**
     * Lock the session if it has been idle too long. A session with no
     * recorded activity that was signed in by a "Remember me" cookie means
     * the real session ended, so it starts locked (this also covers the
     * "code on every sign-in" setting).
     */
    public function lockIfIdle(User $user, Session $session, bool $viaRemember): void
    {
        if (! $this->watches($user) || $this->isLocked($session)) {
            return;
        }

        $last = $session->get(self::LAST_ACTIVITY);

        if ($last === null) {
            if ($viaRemember) {
                $this->lock($session);
            }

            return;
        }

        if ($this->enabled($user) && now()->timestamp - (int) $last >= (int) $user->idle_lock_minutes * 60) {
            $this->lock($session);
        }
    }

    public function lock(Session $session): void
    {
        $session->put(self::LOCKED, true);
    }

    public function touch(Session $session): void
    {
        $session->put(self::LAST_ACTIVITY, now()->timestamp);
    }

    public function unlock(Session $session): void
    {
        $session->forget([self::LOCKED, self::CODE_SENT]);
        $this->touch($session);
    }

    /** Whether a code has already been sent for the current lock. */
    public function codeSent(Session $session): bool
    {
        return (bool) $session->get(self::CODE_SENT, false);
    }

    public function markCodeSent(Session $session): void
    {
        $session->put(self::CODE_SENT, true);
    }
}
