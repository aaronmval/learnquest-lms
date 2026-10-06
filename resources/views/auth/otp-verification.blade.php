{{-- Shared by sign-up / password-reset verification and the idle-lock
     unlock screen (SessionLockController), which passes its own form
     targets, heading and a sign-out link instead of "Back to Login". --}}
@php
  $heading = $heading ?? 'OTP Verification';
  $verifyAction = $verifyAction ?? route('otp.verify');
  $resendAction = $resendAction ?? route('otp.resend');
  $signOut = $signOut ?? false;
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  @if ($signOut)
    {{-- A locked page load inside the app's frame: show this full-window. --}}
    <script>if (window.top !== window.self) window.top.location.replace(window.location.href);</script>
  @endif
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{ $heading }} | LearnQuest</title>
  <link rel="icon" type="image/svg+xml" href="/assets/images/LearnQuestLogo.svg">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
  <link rel="stylesheet" href="/assets/css/pages/auth/forgot-password.css">
  <script src="/assets/js/auth-background.js" defer></script>
</head>
<body>

  <div class="otp-container">
    <h1>{{ $heading }}</h1>
    @if ($signOut)
      <p class="subtitle">Your session is locked to keep your account safe.</p>
    @endif
    <p class="subtitle">Your verification code has been sent to<br><span id="emailDisplay" style="font-weight: 600;">{{ $email }}</span></p>

    @if ($errors->has('code') && !$locked)
      <div class="error-message show" id="errorMessage">
        {{ $errors->first('code') }}
      </div>
    @endif

    @if (session('status'))
      <div class="success-message show" id="successMessage">
        {{ session('status') }}
      </div>
    @endif

    <div class="locked-message {{ $locked ? 'show' : '' }}" id="lockedMessage">
      <i class="fas fa-lock"></i> Too many failed attempts. Please request a new code to continue.
    </div>

    <form method="POST" action="{{ $verifyAction }}" id="otpForm" autocomplete="off">
      @csrf
      <input type="hidden" name="code" id="codeInput">

      <p class="input-label">Enter Verification Code</p>

      <div class="otp-inputs {{ $locked ? 'disabled' : '' }}" id="otpInputsWrapper">
        @for ($i = 0; $i < $length; $i++)
          <input type="text" class="otp-input" maxlength="1" pattern="\d*" inputmode="numeric" @disabled($locked)>
        @endfor
      </div>

      <!-- Attempts remaining indicator -->
      <p class="attempts-label {{ $locked ? 'hidden' : '' }} {{ $attemptsLeft < $maxAttempts ? 'warning' : '' }}" id="attemptsLabel">
        You have <span id="attemptsCount">{{ $attemptsLeft }}</span> {{ $attemptsLeft === 1 ? 'attempt' : 'attempts' }} remaining
      </p>

      <button type="submit" class="btn-verify" id="verifyBtn" disabled>
        <span id="verifyBtnText">VERIFY</span>
        <span class="btn-spinner" id="verifyBtnSpinner"></span>
      </button>
    </form>

    <div class="resend-section">
      Didn't receive the OTP code?
      <form method="POST" action="{{ $resendAction }}">
        @csrf
        <button type="submit" class="resend-link {{ $locked ? 'pulse' : '' }}" id="resendBtn">Resend OTP</button>
      </form>
      <span class="resend-timer" id="resendTimer">Resend available in <span id="timerCount">{{ $resendIn }}</span>s</span>
    </div>

    @if ($signOut)
      <form method="POST" action="{{ route('logout') }}" id="signOutForm">
        @csrf
      </form>
      <a href="{{ route('login') }}" class="back-link" onclick="event.preventDefault(); document.getElementById('signOutForm').submit();">
        <i class="fas fa-sign-out-alt"></i> Sign out instead
      </a>
    @else
      <a href="{{ route('login') }}" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Login
      </a>
    @endif
  </div>

  <script>
    const inputs = document.querySelectorAll('.otp-input');
    const otpForm = document.getElementById('otpForm');
    const codeInput = document.getElementById('codeInput');
    const verifyBtn = document.getElementById('verifyBtn');
    const verifyBtnText = document.getElementById('verifyBtnText');
    const resendBtn = document.getElementById('resendBtn');
    const resendTimer = document.getElementById('resendTimer');
    const timerCount = document.getElementById('timerCount');

    // State comes from the server; the browser only drives the inputs.
    const isLocked = @json($locked);
    let secondsLeft = @json($resendIn);
    let submitting = false;

    if (!isLocked) {
      inputs[0].focus();
    }

    // Resend cooldown timer
    if (secondsLeft > 0) {
      resendBtn.style.display = 'none';
      resendTimer.classList.add('show');

      const resendInterval = setInterval(() => {
        secondsLeft--;
        timerCount.textContent = secondsLeft;

        if (secondsLeft <= 0) {
          clearInterval(resendInterval);
          resendTimer.classList.remove('show');
          resendBtn.style.display = 'inline';
        }
      }, 1000);
    }

    function allFilled() {
      return Array.from(inputs).every(input => input.value !== '');
    }

    function checkAllFilled() {
      verifyBtn.disabled = !allFilled() || isLocked;
    }

    function submitOtp() {
      if (isLocked || submitting || !allFilled()) return;

      submitting = true;
      codeInput.value = Array.from(inputs).map(input => input.value).join('');
      verifyBtn.classList.add('loading');
      verifyBtn.disabled = true;
      verifyBtnText.style.visibility = 'hidden';
      otpForm.submit();
    }

    // Input handling
    inputs.forEach((input, index) => {
      input.addEventListener('input', (e) => {
        const value = e.target.value;

        // Only allow numbers
        if (!/^\d*$/.test(value)) {
          e.target.value = '';
          return;
        }

        // Move to next input if value is entered
        if (value && index < inputs.length - 1) {
          inputs[index + 1].focus();
        }

        checkAllFilled();

        // Auto-submit once every box is filled
        submitOtp();
      });

      // Handle backspace
      input.addEventListener('keydown', (e) => {
        if (e.key === 'Backspace' && !e.target.value && index > 0) {
          inputs[index - 1].focus();
        }
      });

      // Handle paste
      input.addEventListener('paste', (e) => {
        e.preventDefault();
        if (isLocked) return;
        const pastedData = e.clipboardData.getData('text').trim().slice(0, inputs.length);

        if (/^\d+$/.test(pastedData)) {
          pastedData.split('').forEach((char, i) => {
            if (inputs[i]) {
              inputs[i].value = char;
            }
          });
          inputs[Math.min(pastedData.length, inputs.length - 1)].focus();
          checkAllFilled();
          submitOtp();
        }
      });
    });

    otpForm.addEventListener('submit', (e) => {
      e.preventDefault();
      submitOtp();
    });
  </script>

</body>
</html>
