<?php

namespace Tests\Feature;

use App\Mail\OtpCodeMail;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OtpVerificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_registration_emails_a_code_and_does_not_sign_the_user_in(): void
    {
        $this->post('/register', $this->registration())->assertRedirect(route('otp.show'));

        $this->assertGuest();
        $this->assertNull(User::query()->where('email', 'maria@example.com')->sole()->email_verified_at);
        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->hasTo('maria@example.com')
            && $mail->purpose === OtpService::PURPOSE_REGISTRATION);

        $this->get('/verify-otp')->assertOk()->assertSee('maria@example.com');
    }

    public function test_correct_code_verifies_the_email_and_signs_the_user_in(): void
    {
        $this->post('/register', $this->registration());

        $this->post('/verify-otp', ['code' => $this->sentCode()])
            ->assertRedirect(route('student.dashboard'));

        $user = User::query()->where('email', 'maria@example.com')->sole();
        $this->assertNotNull($user->email_verified_at);
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_code_is_rejected(): void
    {
        $this->post('/register', $this->registration());
        $code = $this->sentCode();

        $this->from('/verify-otp')->post('/verify-otp', ['code' => $code === '000000' ? '111111' : '000000'])
            ->assertRedirect('/verify-otp')
            ->assertSessionHasErrors('code');

        $this->assertGuest();
        $this->assertNull(User::query()->where('email', 'maria@example.com')->sole()->email_verified_at);
    }

    public function test_expired_code_is_rejected(): void
    {
        $this->post('/register', $this->registration());
        $code = $this->sentCode();

        $this->travel(config('otp.expires_minutes') + 1)->minutes();

        $this->from('/verify-otp')->post('/verify-otp', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    public function test_unverified_user_logging_in_is_sent_to_otp(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'student', 'password' => 'secret-password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect(route('otp.show'));

        $this->assertGuest();
        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->hasTo($user->email));
    }

    public function test_verified_user_logs_in_without_otp(): void
    {
        $user = User::factory()->create(['role' => 'professor', 'password' => 'secret-password']);

        $this->post('/login', ['email' => $user->email, 'password' => 'secret-password'])
            ->assertRedirect(route('professor.dashboard'));

        $this->assertAuthenticatedAs($user);
        Mail::assertNothingSent();
    }

    public function test_forgot_password_responds_the_same_for_known_and_unknown_emails(): void
    {
        $user = User::factory()->create();

        $known = $this->post('/forgot-password', ['email' => $user->email]);
        $known->assertRedirect(route('otp.show'))->assertSessionHasNoErrors();
        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->hasTo($user->email)
            && $mail->purpose === OtpService::PURPOSE_PASSWORD_RESET);
        Mail::assertSentCount(1);

        $unknown = $this->post('/forgot-password', ['email' => 'nobody@example.com']);
        $unknown->assertRedirect(route('otp.show'))->assertSessionHasNoErrors();
        Mail::assertSentCount(1);

        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
    }

    public function test_valid_reset_code_leads_to_a_working_password_reset(): void
    {
        $user = User::factory()->create(['password' => 'old-password']);

        $this->post('/forgot-password', ['email' => $user->email]);
        $response = $this->post('/verify-otp', ['code' => $this->sentCode()]);

        $location = $response->headers->get('Location');
        $this->assertStringContainsString('/reset-password/', $location);
        $this->assertGuest();

        $token = basename(parse_url($location, PHP_URL_PATH));

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'new-password-123',
            'password_confirmation' => 'new-password-123',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('new-password-123', $user->fresh()->password));
    }

    public function test_resend_is_refused_during_cooldown(): void
    {
        $this->post('/register', $this->registration());

        $this->from('/verify-otp')->post('/verify-otp/resend')->assertSessionHasErrors('code');
        Mail::assertSentCount(1);

        $this->travel(config('otp.resend_cooldown_seconds') + 1)->seconds();

        $this->from('/verify-otp')->post('/verify-otp/resend')->assertSessionHasNoErrors();
        Mail::assertSentCount(2);
    }

    public function test_otp_page_requires_a_pending_verification(): void
    {
        $this->get('/verify-otp')->assertRedirect(route('login'));
        $this->post('/verify-otp', ['code' => '123456'])->assertRedirect(route('login'));
    }

    /**
     * @return array<string, string>
     */
    private function registration(): array
    {
        return [
            'name' => 'Maria Santos',
            'email' => 'maria@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'role' => 'student',
        ];
    }

    private function sentCode(): string
    {
        $code = '';

        Mail::assertSent(OtpCodeMail::class, function (OtpCodeMail $mail) use (&$code) {
            $code = $mail->code;

            return true;
        });

        return $code;
    }
}
