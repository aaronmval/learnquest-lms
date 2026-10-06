<?php

namespace App\Http\Controllers;

use App\Http\Requests\VerifyOtpRequest;
use App\Models\User;
use App\Services\Auth\OtpService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Password;
use Illuminate\View\View;

class OtpController extends Controller
{
    public function __construct(private OtpService $otp)
    {
    }

    public function show(Request $request): View|RedirectResponse
    {
        [$email, $purpose] = $this->pending($request);

        if ($email === null) {
            return redirect()->route('login');
        }

        $attemptsLeft = $this->otp->attemptsRemaining($email, $purpose);

        return view('auth.otp-verification', [
            'heading' => $purpose === OtpService::PURPOSE_LOGIN ? 'Sign-in Verification' : 'OTP Verification',
            'email' => $email,
            'length' => (int) config('otp.length'),
            'attemptsLeft' => $attemptsLeft,
            'maxAttempts' => (int) config('otp.max_attempts'),
            'locked' => $attemptsLeft === 0,
            'resendIn' => $this->otp->secondsUntilResend($email, $purpose),
        ]);
    }

    public function verify(VerifyOtpRequest $request): RedirectResponse
    {
        [$email, $purpose] = $this->pending($request);

        if ($email === null) {
            return redirect()->route('login');
        }

        $result = $this->otp->verify($email, $purpose, $request->validated()['code']);

        if ($result !== OtpService::VERIFY_OK) {
            return back()->withErrors(['code' => match ($result) {
                OtpService::VERIFY_EXPIRED => 'This code has expired. Please request a new one.',
                OtpService::VERIFY_LOCKED => 'Too many failed attempts. Please request a new code to continue.',
                default => 'Invalid OTP code. Please try again.',
            }]);
        }

        $remember = (bool) $request->session()->get('otp.remember', false);
        $request->session()->forget('otp');

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            return redirect()->route('login');
        }

        // Settings → Security sign-in code: the password was already checked.
        if ($purpose === OtpService::PURPOSE_LOGIN) {
            Auth::login($user, $remember);
            $request->session()->regenerate();

            return redirect()->route($user->role === 'professor' ? 'professor.dashboard' : 'student.dashboard');
        }

        if ($purpose === OtpService::PURPOSE_PASSWORD_RESET) {
            return redirect()->route('password.reset', [
                'token' => Password::broker()->createToken($user),
                'email' => $user->email,
            ]);
        }

        $user->forceFill(['email_verified_at' => now()])->save();

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route($user->role === 'professor' ? 'professor.dashboard' : 'student.dashboard');
    }

    public function resend(Request $request): RedirectResponse
    {
        [$email, $purpose] = $this->pending($request);

        if ($email === null) {
            return redirect()->route('login');
        }

        $user = User::query()->where('email', $email)->first();
        $result = $this->otp->issue($email, $purpose, $user);

        if ($result === OtpService::ISSUE_COOLDOWN) {
            return back()->withErrors(['code' => 'Please wait before requesting another code.']);
        }

        if ($result === OtpService::ISSUE_FAILED) {
            return back()->withErrors(['code' => 'We could not send the email right now. Please try again.']);
        }

        return back()->with('status', 'A new OTP has been sent to your email!');
    }

    /**
     * The email + purpose awaiting verification in this session.
     *
     * @return array{0: ?string, 1: ?string}
     */
    private function pending(Request $request): array
    {
        return [
            $request->session()->get('otp.email'),
            $request->session()->get('otp.purpose'),
        ];
    }
}
