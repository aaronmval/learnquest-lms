<?php

namespace Tests\Feature;

use App\Mail\OtpCodeMail;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Laravel\Socialite\Contracts\Factory as SocialiteFactory;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as GoogleUser;
use Mockery;
use Tests\TestCase;

/**
 * Socialite is mocked throughout: no request ever reaches Google.
 */
class GoogleAuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);
    }

    private const PHOTO = 'https://lh3.googleusercontent.com/a/photo=s96-c';

    private function fakeGoogleUser(string $email = 'maria@example.com', bool $verified = true, string $id = 'google-123', ?string $avatar = null): void
    {
        $google = (new GoogleUser)->setRaw(['email_verified' => $verified])->map([
            'id' => $id,
            'name' => 'Maria Santos',
            'email' => $email,
            'avatar' => $avatar,
        ]);

        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andReturn($google);
        $this->fakeSocialite($provider);
    }

    /** Swap in a fake Socialite; callable again to sign in a second time in one test. */
    private function fakeSocialite(object $provider): void
    {
        $manager = Mockery::mock(SocialiteFactory::class);
        $manager->shouldReceive('driver')->with('google')->andReturn($provider);

        Socialite::swap($manager);
    }

    public function test_an_existing_user_is_signed_in_and_linked(): void
    {
        $user = User::factory()->create(['role' => 'professor', 'email' => 'maria@example.com']);
        $this->fakeGoogleUser('Maria@Example.com');

        $this->get('/auth/google/callback')->assertRedirect(route('professor.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertSame('google-123', $user->fresh()->google_id);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_user_who_wants_a_sign_in_code_gets_one_after_google(): void
    {
        Mail::fake();
        User::factory()->create(['role' => 'student', 'email' => 'maria@example.com', 'otp_on_login' => true]);
        $this->fakeGoogleUser();

        $this->get('/auth/google/callback')->assertRedirect(route('otp.show'));

        $this->assertGuest();
        Mail::assertSent(OtpCodeMail::class, fn (OtpCodeMail $mail) => $mail->purpose === OtpService::PURPOSE_LOGIN);
    }

    public function test_an_unverified_existing_account_becomes_verified_without_an_otp(): void
    {
        $user = User::factory()->unverified()->create(['role' => 'student', 'email' => 'maria@example.com']);
        $this->fakeGoogleUser();

        $this->get('/auth/google/callback')->assertRedirect(route('student.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh()->email_verified_at);
        $this->assertDatabaseCount('otp_codes', 0);
    }

    public function test_a_linked_account_is_found_by_its_google_id_even_if_the_email_changed(): void
    {
        $user = User::factory()->create(['role' => 'student', 'email' => 'old@example.com']);
        $user->forceFill(['google_id' => 'google-123'])->save();
        $this->fakeGoogleUser('new@example.com');

        $this->get('/auth/google/callback')->assertRedirect(route('student.dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_new_user_chooses_a_role_and_gets_a_verified_account(): void
    {
        $this->fakeGoogleUser();

        $this->get('/auth/google/callback')->assertRedirect(route('google.role'));
        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);

        $this->get('/auth/google/role')->assertOk()->assertSee('Maria Santos')->assertSee('maria@example.com');

        $this->post('/auth/google/role', ['role' => 'admin'])->assertSessionHasErrors('role');
        $this->assertDatabaseCount('users', 0);

        $this->post('/auth/google/role', ['role' => 'professor'])->assertRedirect(route('professor.dashboard'));

        $user = User::firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('Maria Santos', $user->name);
        $this->assertSame('maria@example.com', $user->email);
        $this->assertSame('professor', $user->role);
        $this->assertSame('google-123', $user->google_id);
        $this->assertNotNull($user->email_verified_at);

        // The pending profile is used once.
        $this->post('/logout');
        $this->get('/auth/google/role')->assertRedirect(route('login'));
    }

    public function test_the_role_page_needs_a_pending_google_profile(): void
    {
        $this->get('/auth/google/role')->assertRedirect(route('login'));
        $this->post('/auth/google/role', ['role' => 'student'])->assertRedirect(route('login'));
        $this->assertDatabaseCount('users', 0);
    }

    public function test_an_unverified_google_email_is_rejected(): void
    {
        $this->fakeGoogleUser(verified: false);

        $this->get('/auth/google/callback')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrorsIn('login', 'email');

        $this->assertGuest();
        $this->assertDatabaseCount('users', 0);
    }

    public function test_a_cancelled_or_failed_google_response_returns_to_login(): void
    {
        $provider = Mockery::mock();
        $provider->shouldReceive('user')->andThrow(new InvalidStateException);
        $this->fakeSocialite($provider);

        $this->get('/auth/google/callback?error=access_denied')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrorsIn('login', 'email');

        $this->assertGuest();
    }

    public function test_the_button_and_routes_exist_only_when_google_is_configured(): void
    {
        $this->get('/login')->assertOk()->assertSee('Continue with Google');
        $this->get('/auth/google')->assertRedirectContains('accounts.google.com');

        config(['services.google.client_id' => null]);

        $this->get('/login')->assertOk()->assertDontSee('Continue with Google');
        $this->get('/auth/google')->assertNotFound();
        $this->get('/auth/google/callback')->assertNotFound();
    }

    public function test_a_new_google_user_gets_their_google_name_and_photo(): void
    {
        Storage::fake('local');
        Http::fake(['lh3.googleusercontent.com/*' => Http::response('fake-jpeg-bytes', 200, ['Content-Type' => 'image/jpeg'])]);
        $this->fakeGoogleUser(avatar: self::PHOTO);

        $this->get('/auth/google/callback')->assertRedirect(route('google.role'));
        $this->post('/auth/google/role', ['role' => 'student'])->assertRedirect(route('student.dashboard'));

        $user = User::firstOrFail();
        $this->assertSame('Maria Santos', $user->name);
        $this->assertNotNull($user->avatar_path);
        $this->assertStringStartsWith("avatars/{$user->id}/", $user->avatar_path);
        Storage::disk('local')->assertExists($user->avatar_path);

        // A sharper size than Google's default thumbnail is requested.
        Http::assertSent(fn ($request) => $request->url() === 'https://lh3.googleusercontent.com/a/photo=s256-c');

        // It is served like an uploaded photo.
        $this->actingAs($user)->getJson('/settings')->assertJsonPath('avatar_url', $user->avatarUrl());
        $this->actingAs($user)->get('/settings/avatar')->assertOk();
    }

    public function test_an_existing_account_keeps_its_own_name_and_photo(): void
    {
        Storage::fake('local');
        Http::fake(['lh3.googleusercontent.com/*' => Http::response('fake-jpeg-bytes', 200, ['Content-Type' => 'image/jpeg'])]);

        $withPhoto = User::factory()->create(['role' => 'student', 'email' => 'maria@example.com', 'name' => 'Ria S.']);
        $withPhoto->update(['avatar_path' => 'avatars/own.png']);
        $this->fakeGoogleUser(avatar: self::PHOTO);

        $this->get('/auth/google/callback')->assertRedirect(route('student.dashboard'));

        $this->assertSame('Ria S.', $withPhoto->fresh()->name);
        $this->assertSame('avatars/own.png', $withPhoto->fresh()->avatar_path);
        Http::assertNothingSent();
    }

    public function test_an_existing_account_without_a_photo_gets_the_google_one(): void
    {
        Storage::fake('local');
        Http::fake(['lh3.googleusercontent.com/*' => Http::response('fake-png-bytes', 200, ['Content-Type' => 'image/png'])]);

        $user = User::factory()->create(['role' => 'student', 'email' => 'maria@example.com', 'name' => 'Ria S.']);
        $this->fakeGoogleUser(avatar: self::PHOTO);

        $this->get('/auth/google/callback')->assertRedirect(route('student.dashboard'));

        $this->assertSame('Ria S.', $user->fresh()->name);
        $this->assertStringEndsWith('.png', $user->fresh()->avatar_path);
    }

    public function test_a_bad_or_foreign_photo_is_skipped_without_blocking_sign_in(): void
    {
        Storage::fake('local');
        Http::fake(['*' => Http::response('<html>not an image</html>', 200, ['Content-Type' => 'text/html'])]);
        $user = User::factory()->create(['role' => 'student', 'email' => 'maria@example.com']);

        // Not an image.
        $this->fakeGoogleUser(avatar: self::PHOTO);
        $this->get('/auth/google/callback')->assertRedirect(route('student.dashboard'));
        $this->assertNull($user->fresh()->avatar_path);

        // Not a Google image host: never fetched.
        $this->post('/logout');
        Http::fake();
        $this->fakeGoogleUser(avatar: 'https://evil.example.com/photo.jpg');
        $this->get('/auth/google/callback')->assertRedirect(route('student.dashboard'));
        $this->assertNull($user->fresh()->avatar_path);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'evil.example.com'));
    }

    public function test_the_google_id_is_never_sent_to_the_browser(): void
    {
        $user = User::factory()->create(['role' => 'student']);
        $user->forceFill(['google_id' => 'google-123'])->save();

        $this->assertArrayNotHasKey('google_id', $user->fresh()->toArray());
    }
}
