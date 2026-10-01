<?php

namespace App\Services\Auth;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Throwable;

class OtpService
{
    public const PURPOSE_REGISTRATION = 'registration';
    public const PURPOSE_PASSWORD_RESET = 'password_reset';

    public const ISSUE_SENT = 'sent';
    public const ISSUE_COOLDOWN = 'cooldown';
    public const ISSUE_FAILED = 'failed';

    public const VERIFY_OK = 'ok';
    public const VERIFY_INVALID = 'invalid';
    public const VERIFY_EXPIRED = 'expired';
    public const VERIFY_LOCKED = 'locked';

    /**
     * Generate a fresh code for the email + purpose and email it.
     *
     * When $user is null (no such account) a record is still stored, with a
     * hash no code can match and no email sent, so the OTP page behaves the
     * same whether or not the address is registered.
     */
    public function issue(string $email, string $purpose, ?User $user): string
    {
        $existing = $this->find($email, $purpose);

        if ($existing !== null && $this->cooldownRemaining($existing) > 0) {
            return self::ISSUE_COOLDOWN;
        }

        $length = (int) config('otp.length');
        $expiresMinutes = (int) config('otp.expires_minutes');
        $code = str_pad((string) random_int(0, (10 ** $length) - 1), $length, '0', STR_PAD_LEFT);

        OtpCode::query()->updateOrCreate(
            ['email' => $email, 'purpose' => $purpose],
            [
                'code_hash' => Hash::make($user !== null ? $code : Str::random(40)),
                'attempts' => 0,
                'expires_at' => now()->addMinutes($expiresMinutes),
                'last_sent_at' => now(),
            ]
        );

        if ($user === null) {
            return self::ISSUE_SENT;
        }

        try {
            Mail::to($user->email)->send(new OtpCodeMail($user->name, $code, $purpose, $expiresMinutes));
        } catch (Throwable $e) {
            Log::error('OTP email could not be sent.', [
                'purpose' => $purpose,
                'error' => $e->getMessage(),
            ]);

            // Let the user retry straight away instead of waiting out a
            // cooldown for an email that never left.
            OtpCode::query()
                ->where('email', $email)
                ->where('purpose', $purpose)
                ->update(['last_sent_at' => null]);

            return self::ISSUE_FAILED;
        }

        return self::ISSUE_SENT;
    }

    /**
     * Check a submitted code. A correct code is consumed.
     */
    public function verify(string $email, string $purpose, string $code): string
    {
        $record = $this->find($email, $purpose);

        if ($record === null) {
            return self::VERIFY_INVALID;
        }

        if ($record->attempts >= (int) config('otp.max_attempts')) {
            return self::VERIFY_LOCKED;
        }

        if ($record->expires_at->isPast()) {
            return self::VERIFY_EXPIRED;
        }

        if (!Hash::check($code, $record->code_hash)) {
            $record->increment('attempts');

            return $record->attempts >= (int) config('otp.max_attempts')
                ? self::VERIFY_LOCKED
                : self::VERIFY_INVALID;
        }

        $record->delete();

        return self::VERIFY_OK;
    }

    public function attemptsRemaining(string $email, string $purpose): int
    {
        $max = (int) config('otp.max_attempts');
        $record = $this->find($email, $purpose);

        return max(0, $max - ($record->attempts ?? 0));
    }

    public function secondsUntilResend(string $email, string $purpose): int
    {
        $record = $this->find($email, $purpose);

        return $record !== null ? $this->cooldownRemaining($record) : 0;
    }

    private function find(string $email, string $purpose): ?OtpCode
    {
        return OtpCode::query()
            ->where('email', $email)
            ->where('purpose', $purpose)
            ->first();
    }

    private function cooldownRemaining(OtpCode $record): int
    {
        if ($record->last_sent_at === null) {
            return 0;
        }

        $elapsed = $record->last_sent_at->diffInSeconds(now());

        return (int) max(0, ceil((int) config('otp.resend_cooldown_seconds') - $elapsed));
    }
}
