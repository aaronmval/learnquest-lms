<?php

namespace Tests\Feature;

use App\Mail\OtpCodeMail;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class SessionLockTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_the_lock_is_off_by_default(): void
    {
        $user = User::factory()->create(['role' => 'student']);

        $this->actingAs($user)->getJson('/settings')->assertOk()->assertJsonPath('idle_lock_minutes', 0);

        $this->travel(3)->hours();
        $this->actingAs($user)->getJson('/settings')->assertOk();
        Mail::assertNothingSent();
    }

    public function test_security_setting_accepts_only_the_offered_choices(): void
    {
        $user = User::factory()->create(['role' => 'professor']);

        $this->actingAs($user)->putJson('/settings/security', ['idle_lock_minutes' => 30])
            ->assertOk()
            ->assertJsonPath('idle_lock_minutes', 30);
        $this->assertSame(30, $user->fresh()->idle_lock_minutes);

        $this->actingAs($user)->putJson('/settings/security', ['idle_lock_minutes' => 5])
            ->assertStatus(422)->assertJsonValidationErrors('idle_lock_minutes');

        $this->actingAs($user)->putJson('/settings/security', ['idle_lock_minutes' => 0])
            ->assertOk()->assertJsonPath('idle_lock_minutes', 0);
    }

    public function test_an_idle_session_locks_and_refuses_requests(): void
    {
        $user = $this->userWithLock(15);

        $this->actingAs($user)->getJson('/settings')->assertOk();

        $this->travel(14)->minutes();
        $this->getJson('/settings')->assertOk();

        // 14 minutes after the last request is still inside the limit…
        $this->travel(14)->minutes();
        $this->getJson('/settings')->assertOk();

        // …but 16 idle minutes is not.
        $this->travel(16)->minutes();
        $this->getJson('/settings')->assertStatus(423)->assertJsonPath('locked', true);
        $this->get('/settings')->assertRedirect(route('session-lock.show'));
        $this->getJson('/session/status')->assertOk()->assertJsonPath('locked', true);
    }

    public function test_background_requests_do_not_keep_the_session_awake(): void
    {
        $user = $this->userWithLock(15);

        $this->actingAs($user)->getJson('/settings')->assertOk();

        $this->travel(10)->minutes();
        $this->getJson('/notifications', ['X-LQ-Background' => '1'])->assertOk();

        $this->travel(6)->minutes();
        $this->getJson('/settings')->assertStatus(423);
    }

    public function test_a_heartbeat_counts_as_activity(): void
    {
        $user = $this->userWithLock(15);

        $this->actingAs($user)->getJson('/settings')->assertOk();

        $this->travel(10)->minutes();
        $this->postJson('/session/heartbeat')->assertOk();

        $this->travel(10)->minutes();
        $this->getJson('/settings')->assertOk();
    }

    public function test_the_emailed_code_unlocks_the_session(): void
    {
        $user = $this->userWithLock(15);

        $this->actingAs($user)->getJson('/settings')->assertOk();
        $this->travel(16)->minutes();

        $this->get('/session-locked')->assertOk()->assertSee('Session Locked');
        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->hasTo($user->email)
            && $mail->purpose === OtpService::PURPOSE_SESSION_UNLOCK
            && str_ends_with($mail->envelope()->subject, 'is your LearnQuest unlock code'));

        // Opening the lock screen again doesn't send a second email.
        $this->get('/session-locked')->assertOk();
        Mail::assertSentCount(1);

        $this->post('/session-locked', ['code' => $this->sentCode()])
            ->assertRedirect(route('student.dashboard'));

        $this->getJson('/settings')->assertOk();
        $this->get('/session-locked')->assertRedirect(route('student.dashboard'));
    }

    public function test_the_shell_overlay_locks_and_unlocks_over_json(): void
    {
        $user = $this->userWithLock(15);

        $this->actingAs($user)->postJson('/session/lock')
            ->assertOk()
            ->assertJsonPath('locked', true)
            ->assertJsonPath('email', 'ma***@example.com')
            ->assertJsonPath('length', (int) config('otp.length'));

        $this->getJson('/settings')->assertStatus(423);

        $this->postJson('/session-locked', ['code' => $this->wrongCode()])
            ->assertStatus(422)
            ->assertJsonPath('locked', true)
            ->assertJsonValidationErrors('code');

        $this->postJson('/session-locked', ['code' => $this->sentCode()])
            ->assertOk()
            ->assertJsonPath('locked', false);

        $this->getJson('/settings')->assertOk();
    }

    public function test_too_many_wrong_codes_need_a_new_code(): void
    {
        $user = $this->userWithLock(15);
        $this->actingAs($user)->postJson('/session/lock')->assertOk();

        for ($i = 0; $i < (int) config('otp.max_attempts'); $i++) {
            $this->postJson('/session-locked', ['code' => $this->wrongCode()])->assertStatus(422);
        }

        // Even the right code is refused now.
        $this->postJson('/session-locked', ['code' => $this->sentCode()])
            ->assertStatus(422)->assertJsonPath('attempts_left', 0);
        $this->getJson('/settings')->assertStatus(423);
    }

    public function test_a_remember_me_sign_in_starts_locked(): void
    {
        $user = $this->userWithLock(15);
        $recaller = Auth::guard()->getRecallerName();

        $cookie = $this->post('/login', ['email' => $user->email, 'password' => 'password', 'remember' => '1'])
            ->assertRedirect()
            ->getCookie($recaller, false);
        $this->assertNotNull($cookie);

        // The session has ended; only the remember-me cookie is left.
        $this->flushSession();
        Auth::forgetGuards();

        // JSON test requests only send cookies with credentials.
        $this->withCredentials()
            ->withUnencryptedCookie($recaller, $cookie->getValue())
            ->getJson('/settings')
            ->assertStatus(423);
    }

    public function test_sign_out_works_while_locked(): void
    {
        $user = $this->userWithLock(15);
        $this->actingAs($user)->postJson('/session/lock')->assertOk();

        $this->post('/logout')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    private function userWithLock(int $minutes): User
    {
        return User::factory()->create([
            'role' => 'student',
            'email' => 'maria@example.com',
            'idle_lock_minutes' => $minutes,
        ]);
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
