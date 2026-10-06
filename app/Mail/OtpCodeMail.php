<?php

namespace App\Mail;

use App\Services\Auth\OtpService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OtpCodeMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $name,
        public string $code,
        public string $purpose,
        public int $expiresMinutes,
    ) {
    }

    public function envelope(): Envelope
    {
        $subject = match ($this->purpose) {
            OtpService::PURPOSE_PASSWORD_RESET => "{$this->code} is your LearnQuest password reset code",
            OtpService::PURPOSE_SESSION_UNLOCK => "{$this->code} is your LearnQuest unlock code",
            OtpService::PURPOSE_LOGIN => "{$this->code} is your LearnQuest sign-in code",
            default => "{$this->code} is your LearnQuest verification code",
        };

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.otp',
            text: 'emails.otp-text',
            with: match ($this->purpose) {
                OtpService::PURPOSE_PASSWORD_RESET => [
                    'heading' => 'Reset your password',
                    'intro' => 'We received a request to reset the password for your LearnQuest account. Enter the code below to continue.',
                    'ignoreNote' => 'If you did not request a password reset, you can safely ignore this email. Your password will not change.',
                ],
                OtpService::PURPOSE_LOGIN => [
                    'heading' => 'Confirm it\'s you',
                    'intro' => 'Someone just entered the correct password for your LearnQuest account. Enter the code below to finish signing in.',
                    'ignoreNote' => 'If this wasn\'t you, do not share this code. Change your LearnQuest password as soon as you can.',
                ],
                OtpService::PURPOSE_SESSION_UNLOCK => [
                    'heading' => 'Unlock your session',
                    'intro' => 'Your LearnQuest session is locked to keep your account safe. Enter the code below to continue where you left off.',
                    'ignoreNote' => 'If you did not just try to unlock LearnQuest, someone may be using a device you are signed in on. Sign out there and change your password.',
                ],
                default => [
                    'heading' => 'Verify your email address',
                    'intro' => 'Welcome to LearnQuest! Enter the code below to verify your email address and finish creating your account.',
                    'ignoreNote' => 'If you did not create a LearnQuest account, you can safely ignore this email.',
                ],
            },
        );
    }
}
