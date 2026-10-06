<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settings → Security "Ask for a code every time I sign in". After the
 * password (or Google) check succeeds, the user is not signed in yet: a
 * code is emailed and OtpController finishes the sign-in once it is entered.
 */
class LoginChallengeService
{
    public function __construct(private OtpService $otp)
    {
    }

    public function required(User $user): bool
    {
        return (bool) $user->otp_on_login;
    }

    /** Email a sign-in code and send the browser to the code page. */
    public function begin(Request $request, User $user, bool $remember): RedirectResponse
    {
        $result = $this->otp->issue($user->email, OtpService::PURPOSE_LOGIN, $user);

        $request->session()->put('otp', [
            'email' => $user->email,
            'purpose' => OtpService::PURPOSE_LOGIN,
            'remember' => $remember,
        ]);

        $redirect = redirect()->route('otp.show');

        if ($result === OtpService::ISSUE_FAILED) {
            return $redirect->withErrors([
                'code' => 'We could not send the sign-in code right now. Please use Resend OTP to try again.',
            ]);
        }

        return $redirect->with('status', 'Enter the code we emailed you to finish signing in.');
    }
}
