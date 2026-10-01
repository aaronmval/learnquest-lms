<?php

namespace Tests\Unit;

use App\Mail\OtpCodeMail;
use App\Models\OtpCode;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->otp = new OtpService();
    }

    public function test_issue_emails_a_numeric_code_and_stores_only_its_hash(): void
    {
        $user = User::factory()->create();

        $result = $this->otp->issue($user->email, OtpService::PURPOSE_REGISTRATION, $user);
        $code = $this->sentCode($user);

        $this->assertSame(OtpService::ISSUE_SENT, $result);
        $this->assertMatchesRegularExpression('/^\d{6}$/', $code);
        $this->assertNotSame($code, OtpCode::query()->sole()->code_hash);
    }

    public function test_correct_code_verifies_once(): void
    {
        $user = User::factory()->create();
        $this->otp->issue($user->email, OtpService::PURPOSE_REGISTRATION, $user);
        $code = $this->sentCode($user);

        $this->assertSame(OtpService::VERIFY_OK, $this->otp->verify($user->email, OtpService::PURPOSE_REGISTRATION, $code));
        $this->assertSame(OtpService::VERIFY_INVALID, $this->otp->verify($user->email, OtpService::PURPOSE_REGISTRATION, $code));
    }

    public function test_code_is_bound_to_its_purpose(): void
    {
        $user = User::factory()->create();
        $this->otp->issue($user->email, OtpService::PURPOSE_REGISTRATION, $user);
        $code = $this->sentCode($user);

        $this->assertSame(
            OtpService::VERIFY_INVALID,
            $this->otp->verify($user->email, OtpService::PURPOSE_PASSWORD_RESET, $code)
        );
    }

    public function test_expired_code_is_rejected(): void
    {
        $user = User::factory()->create();
        $this->otp->issue($user->email, OtpService::PURPOSE_REGISTRATION, $user);
        $code = $this->sentCode($user);

        $this->travel(config('otp.expires_minutes') + 1)->minutes();

        $this->assertSame(OtpService::VERIFY_EXPIRED, $this->otp->verify($user->email, OtpService::PURPOSE_REGISTRATION, $code));
    }

    public function test_code_locks_after_max_wrong_attempts(): void
    {
        $user = User::factory()->create();
        $this->otp->issue($user->email, OtpService::PURPOSE_REGISTRATION, $user);
        $code = $this->sentCode($user);
        $wrong = $code === '000000' ? '111111' : '000000';

        $this->assertSame(OtpService::VERIFY_INVALID, $this->otp->verify($user->email, OtpService::PURPOSE_REGISTRATION, $wrong));
        $this->assertSame(2, $this->otp->attemptsRemaining($user->email, OtpService::PURPOSE_REGISTRATION));
        $this->assertSame(OtpService::VERIFY_INVALID, $this->otp->verify($user->email, OtpService::PURPOSE_REGISTRATION, $wrong));
        $this->assertSame(OtpService::VERIFY_LOCKED, $this->otp->verify($user->email, OtpService::PURPOSE_REGISTRATION, $wrong));

        // Even the right code is refused once locked.
        $this->assertSame(OtpService::VERIFY_LOCKED, $this->otp->verify($user->email, OtpService::PURPOSE_REGISTRATION, $code));
    }

    public function test_resend_is_refused_during_cooldown_then_unlocks_a_locked_code(): void
    {
        $user = User::factory()->create();
        $this->otp->issue($user->email, OtpService::PURPOSE_REGISTRATION, $user);

        $this->assertSame(OtpService::ISSUE_COOLDOWN, $this->otp->issue($user->email, OtpService::PURPOSE_REGISTRATION, $user));
        $this->assertGreaterThan(0, $this->otp->secondsUntilResend($user->email, OtpService::PURPOSE_REGISTRATION));
        Mail::assertSentCount(1);

        OtpCode::query()->update(['attempts' => config('otp.max_attempts')]);
        $this->travel(config('otp.resend_cooldown_seconds') + 1)->seconds();

        $this->assertSame(OtpService::ISSUE_SENT, $this->otp->issue($user->email, OtpService::PURPOSE_REGISTRATION, $user));
        $this->assertSame(config('otp.max_attempts'), $this->otp->attemptsRemaining($user->email, OtpService::PURPOSE_REGISTRATION));
        Mail::assertSentCount(2);
    }

    public function test_unknown_email_gets_a_record_but_no_email(): void
    {
        $result = $this->otp->issue('nobody@example.com', OtpService::PURPOSE_PASSWORD_RESET, null);

        $this->assertSame(OtpService::ISSUE_SENT, $result);
        $this->assertDatabaseHas('otp_codes', ['email' => 'nobody@example.com']);
        Mail::assertNothingSent();
    }

    private function sentCode(User $user): string
    {
        $code = '';

        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use ($user, &$code) {
            $code = $mail->code;

            return $mail->hasTo($user->email);
        });

        return $code;
    }
}
