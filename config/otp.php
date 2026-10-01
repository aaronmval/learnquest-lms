<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Email One-Time Passcodes
    |--------------------------------------------------------------------------
    |
    | Used for new-account email verification and password reset.
    |
    */

    'length' => (int) env('OTP_LENGTH', 6),

    'expires_minutes' => (int) env('OTP_EXPIRES_MINUTES', 10),

    'max_attempts' => (int) env('OTP_MAX_ATTEMPTS', 3),

    'resend_cooldown_seconds' => (int) env('OTP_RESEND_COOLDOWN_SECONDS', 60),

];
