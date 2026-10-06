<?php

namespace App\Http\Controllers;

use App\Http\Requests\ForgotPasswordRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Http\Requests\ResetPasswordRequest;
use App\Models\User;
use App\Services\Auth\LoginChallengeService;
use App\Services\Auth\OtpService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function register(RegisterRequest $request, OtpService $otp): RedirectResponse
    {
        $validated = $request->validated();

        $user = User::query()->create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => $validated['password'],
            'role' => $validated['role'],
        ]);

        // The account stays signed out until the emailed code is confirmed.
        return $this->sendVerificationOtp($request, $user, $otp);
    }

    public function login(LoginRequest $request, OtpService $otp, LoginChallengeService $challenge): RedirectResponse
    {
        $validated = $request->validated();

        $credentials = [
            'email' => $validated['email'],
            'password' => $validated['password'],
        ];

        $remember = (bool) ($validated['remember'] ?? false);

        // Check the password without signing in yet: an unverified account or
        // a sign-in code (Settings → Security) may still be needed.
        if (!Auth::validate($credentials)) {
            return back()
                ->withInput($request->only('email', 'remember'))
                ->withErrors(['email' => 'The provided credentials do not match our records.'], 'login');
        }

        /** @var User $user */
        $user = Auth::getLastAttempted();

        if ($user->email_verified_at === null) {
            return $this->sendVerificationOtp($request, $user, $otp);
        }

        if ($challenge->required($user)) {
            return $challenge->begin($request, $user, $remember);
        }

        Auth::login($user, $remember);
        $request->session()->regenerate();

        return $this->redirectToRoleHome($user->role);
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function showForgotPasswordForm(): View
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(ForgotPasswordRequest $request, OtpService $otp): RedirectResponse
    {
        $email = $request->validated()['email'];
        $user = User::query()->where('email', $email)->first();
        $email = $user->email ?? $email;

        // Same response whether or not the account exists, so this form
        // cannot be used to discover registered emails.
        $otp->issue($email, OtpService::PURPOSE_PASSWORD_RESET, $user);

        $request->session()->put('otp', [
            'email' => $email,
            'purpose' => OtpService::PURPOSE_PASSWORD_RESET,
        ]);

        return redirect()->route('otp.show')
            ->with('status', 'If an account exists for that email, a verification code has been sent.');
    }

    public function showResetPasswordForm(Request $request, string $token): View
    {
        return view('auth.reset-password', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    public function resetPassword(ResetPasswordRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        $status = Password::reset(
            $validated,
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => $password,
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return redirect()->route('login')->with('status', __($status));
        }

        return back()->withErrors(['email' => __($status)], 'reset')->withInput($request->only('email'));
    }

    private function sendVerificationOtp(Request $request, User $user, OtpService $otp): RedirectResponse
    {
        $result = $otp->issue($user->email, OtpService::PURPOSE_REGISTRATION, $user);

        $request->session()->put('otp', [
            'email' => $user->email,
            'purpose' => OtpService::PURPOSE_REGISTRATION,
        ]);

        $redirect = redirect()->route('otp.show');

        if ($result === OtpService::ISSUE_FAILED) {
            return $redirect->withErrors([
                'code' => 'We could not send the verification email right now. Please use Resend OTP to try again.',
            ]);
        }

        return $redirect->with('status', 'Please verify your email to continue.');
    }

    private function redirectToRoleHome(string $role): RedirectResponse
    {
        if ($role === 'professor') {
            return redirect()->route('professor.dashboard');
        }

        return redirect()->route('student.dashboard');
    }
}
