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
        $subject = $this->isPasswordReset()
            ? "{$this->code} is your LearnQuest password reset code"
            : "{$this->code} is your LearnQuest verification code";

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        $reset = $this->isPasswordReset();

        return new Content(
            view: 'emails.otp',
            text: 'emails.otp-text',
            with: [
                'heading' => $reset ? 'Reset your password' : 'Verify your email address',
                'intro' => $reset
                    ? 'We received a request to reset the password for your LearnQuest account. Enter the code below to continue.'
                    : 'Welcome to LearnQuest! Enter the code below to verify your email address and finish creating your account.',
                'ignoreNote' => $reset
                    ? 'If you did not request a password reset, you can safely ignore this email. Your password will not change.'
                    : 'If you did not create a LearnQuest account, you can safely ignore this email.',
            ],
        );
    }

    private function isPasswordReset(): bool
    {
        return $this->purpose === OtpService::PURPOSE_PASSWORD_RESET;
    }
}
