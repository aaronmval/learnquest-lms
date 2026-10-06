<?php

namespace Tests\Feature;

use App\Mail\OtpCodeMail;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Settings → Security "Code on Every Sign-in".
 */
class LoginOtpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_sign_in_needs_no_code_by_default(): void
    {
        $user = User::factory()->create(['role' => 'student']);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('student.dashboard'));

        $this->assertAuthenticatedAs($user);
        Mail::assertNothingSent();
    }

    public function test_with_the_setting_on_the_password_alone_does_not_sign_in(): void
    {
        $user = User::factory()->create(['role' => 'professor', 'otp_on_login' => true]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])
            ->assertRedirect(route('otp.show'));

        $this->assertGuest();
        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->hasTo($user->email)
            && $mail->purpose === OtpService::PURPOSE_LOGIN
            && str_ends_with($mail->envelope()->subject, 'is your LearnQuest sign-in code'));

        $this->get('/verify-otp')->assertOk()->assertSee('Sign-in Verification');

        // Still guarded while waiting for the code.
        $this->get('/professor/dashboard')->assertRedirect(route('login'));
    }

    public function test_the_emailed_code_finishes_signing_in(): void
    {
        $user = User::factory()->create(['role' => 'professor', 'otp_on_login' => true]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->from('/verify-otp')->post('/verify-otp', ['code' => $this->wrongCode()])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        $this->post('/verify-otp', ['code' => $this->sentCode()])
            ->assertRedirect(route('professor.dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_remember_me_is_kept_through_the_code_step(): void
    {
        $user = User::factory()->create(['role' => 'student', 'otp_on_login' => true]);

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
            ->assertCookieMissing(Auth::guard()->getRecallerName());

        $this->post('/verify-otp', ['code' => $this->sentCode()])
            ->assertRedirect(route('student.dashboard'))
            ->assertCookie(Auth::guard()->getRecallerName());
    }

    public function test_a_wrong_password_still_fails_without_sending_a_code(): void
    {
        $user = User::factory()->create(['role' => 'student', 'otp_on_login' => true]);

        $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
            ->assertSessionHasErrors('email', null, 'login');

        $this->assertGuest();
        Mail::assertNothingSent();
    }

    public function test_a_remember_me_sign_in_must_be_unlocked_with_a_code(): void
    {
        $user = User::factory()->create(['role' => 'student', 'otp_on_login' => true]);
        $recaller = Auth::guard()->getRecallerName();

        $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1']);
        $cookie = $this->post('/verify-otp', ['code' => $this->sentCode()])->getCookie($recaller, false);

        // The session has ended; only the remember-me cookie is left.
        $this->flushSession();
        Auth::forgetGuards();

        $this->withCredentials()
            ->withUnencryptedCookie($recaller, $cookie->getValue())
            ->getJson('/settings')
            ->assertStatus(423);
    }

    public function test_the_setting_is_saved_from_the_security_tab(): void
    {
        $user = User::factory()->create(['role' => 'student']);

        $this->actingAs($user)->getJson('/settings')->assertJsonPath('otp_on_login', false);

        $this->actingAs($user)->putJson('/settings/security', ['otp_on_login' => true])
            ->assertOk()
            ->assertJsonPath('otp_on_login', true)
            ->assertJsonPath('idle_lock_minutes', 0);

        $this->actingAs($user)->putJson('/settings/security', ['idle_lock_minutes' => 15, 'otp_on_login' => false])
            ->assertOk()
            ->assertJsonPath('otp_on_login', false)
            ->assertJsonPath('idle_lock_minutes', 15);

        $this->actingAs($user)->putJson('/settings/security', [])->assertStatus(422);
        $this->actingAs($user)->putJson('/settings/security', ['otp_on_login' => 'maybe'])->assertStatus(422);
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

    private function wrongCode(): string
    {
        $code = $this->sentCode();

        return str_repeat($code[0] === '1' ? '2' : '1', strlen($code));
    }
}
