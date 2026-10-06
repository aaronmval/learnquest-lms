<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Auth\LoginChallengeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;
use Throwable;

/**
 * "Continue with Google" on the login page. Google proves the email, so no
 * verification OTP is sent (only the sign-in code, if the user turned that
 * on in Settings → Security); a first-time user still chooses a role
 * before the account is created.
 */
class GoogleAuthController extends Controller
{
    /** Session key holding a new user's Google profile until they pick a role. */
    private const PENDING = 'google_signup';

    /** Same formats and size limit as an uploaded profile photo. */
    private const AVATAR_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    private const AVATAR_MAX_BYTES = 2 * 1024 * 1024;

    public function redirect(): RedirectResponse
    {
        $this->abortUnlessConfigured();

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        $this->abortUnlessConfigured();

        try {
            $google = Socialite::driver('google')->user();
        } catch (Throwable $e) {
            // Cancelled on Google's side, an expired state, or a bad client setup.
            Log::warning('[google-auth] Sign-in did not complete.', [
                'exception' => get_class($e),
                'error' => $e->getMessage(),
            ]);

            return $this->backToLogin('Google sign-in was cancelled or could not be completed. Please try again.');
        }

        $email = Str::lower(trim((string) $google->getEmail()));
        $verified = (bool) ($google->user['email_verified'] ?? $google->user['verified_email'] ?? false);

        if ($email === '' || ! $verified) {
            return $this->backToLogin('Your Google account has no verified email address, so it cannot be used to sign in.');
        }

        $user = User::query()->where('google_id', $google->getId())->first()
            ?? User::query()->where('email', $email)->first();

        if ($user) {
            // Google has verified this address, so the account is too.
            $user->forceFill([
                'google_id' => $google->getId(),
                'email_verified_at' => $user->email_verified_at ?? now(),
            ])->save();

            // An existing account keeps the name and photo its owner chose;
            // the Google photo only fills in when they have none.
            if (! $user->avatar_path) {
                $this->importAvatar($user, $google->getAvatar());
            }

            return $this->signIn($request, $user);
        }

        $request->session()->put(self::PENDING, [
            'id' => (string) $google->getId(),
            'email' => $email,
            'name' => trim((string) ($google->getName() ?: Str::before($email, '@'))),
            'avatar' => $google->getAvatar(),
        ]);

        return redirect()->route('google.role');
    }

    /** A first-time Google user chooses Student or Teacher. */
    public function showRoleForm(Request $request): View|RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);

        if (! $pending) {
            return redirect()->route('login');
        }

        return view('auth.google-role', ['name' => $pending['name'], 'email' => $pending['email']]);
    }

    public function completeSignup(Request $request): RedirectResponse
    {
        $pending = $request->session()->get(self::PENDING);

        if (! $pending) {
            return redirect()->route('login');
        }

        $validated = $request->validate([
            'role' => ['required', Rule::in(['student', 'professor'])],
        ], [
            'role.*' => 'Please choose Student or Teacher.',
        ]);

        // Someone may have registered this email while the role page was open.
        if (User::query()->where('email', $pending['email'])->orWhere('google_id', $pending['id'])->exists()) {
            $request->session()->forget(self::PENDING);

            return $this->backToLogin('An account for this email already exists. Please sign in again.');
        }

        $user = new User;
        $user->forceFill([
            'name' => Str::limit($pending['name'], 255, ''),
            'email' => $pending['email'],
            'google_id' => $pending['id'],
            'role' => $validated['role'],
            // Nobody knows this password; "Forgot Password?" can set a real one.
            'password' => Str::random(40),
            'email_verified_at' => now(),
        ])->save();

        $request->session()->forget(self::PENDING);
        $this->importAvatar($user, $pending['avatar'] ?? null);

        return $this->signIn($request, $user);
    }

    /**
     * Copy the Google profile photo into our own storage as the user's
     * avatar, so it is served like an uploaded one. Best effort: a missing,
     * oversized or non-image photo just leaves the user on initials.
     */
    private function importAvatar(User $user, ?string $url): void
    {
        $host = (string) parse_url((string) $url, PHP_URL_HOST);

        // Only Google's own image hosts are ever fetched.
        if (! $url || parse_url($url, PHP_URL_SCHEME) !== 'https' || ! Str::endsWith($host, '.googleusercontent.com')) {
            return;
        }

        try {
            // Google serves a 96px thumbnail by default; ask for a sharper one.
            $response = Http::timeout(5)->get(preg_replace('/=s\d+(-c)?$/', '=s256-c', $url));

            $extension = self::AVATAR_TYPES[Str::before((string) $response->header('Content-Type'), ';')] ?? null;
            $image = $response->body();

            if (! $response->successful() || $extension === null || $image === '' || strlen($image) > self::AVATAR_MAX_BYTES) {
                return;
            }

            $path = "avatars/{$user->id}/".Str::random(40).".{$extension}";
            Storage::disk()->put($path, $image);
            $user->update(['avatar_path' => $path]);
        } catch (Throwable $e) {
            Log::warning('[google-auth] Could not import the Google profile photo.', [
                'user_id' => $user->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function signIn(Request $request, User $user): RedirectResponse
    {
        // Settings → Security can still ask for an emailed code first.
        $challenge = app(LoginChallengeService::class);
        if ($challenge->required($user)) {
            return $challenge->begin($request, $user, remember: true);
        }

        Auth::login($user, remember: true);
        $request->session()->regenerate();

        return redirect()->route($user->role === 'professor' ? 'professor.dashboard' : 'student.dashboard');
    }

    private function backToLogin(string $message): RedirectResponse
    {
        return redirect()->route('login')->withErrors(['email' => $message], 'login');
    }

    private function abortUnlessConfigured(): void
    {
        abort_unless(filled(config('services.google.client_id')), 404);
    }
}
