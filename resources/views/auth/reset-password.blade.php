<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LearnQuest | Reset Password</title>
  <link rel="icon" type="image/svg+xml" href="/assets/images/LearnQuestLogo.svg">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
  <link rel="stylesheet" href="/assets/css/pages/auth/reset-password.css">
  <style>
    .error-message {
      display: none;
      color: #d32f2f;
      background-color: #ffebee;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 15px;
      font-size: 14px;
      border-left: 4px solid #d32f2f;
    }

    .error-message.show {
      display: block;
    }

    .success-message {
      display: none;
      color: #388e3c;
      background-color: #e8f5e9;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 15px;
      font-size: 14px;
      border-left: 4px solid #388e3c;
    }

    .success-message.show {
      display: block;
    }
  </style>
  <script src="/assets/js/auth-background.js" defer></script>
</head>
<body>

  <div class="reset-container">
    <h1>Reset Password</h1>
    <p class="subtitle">Create a new password for</p>
    <p class="email-display">{{ $email }}</p>

    @if ($errors->has('reset'))
      <div class="error-message show">{{ $errors->first('reset') }}</div>
    @endif

    @if (session('status'))
      <div class="success-message show">
        {{ session('status') }}
      </div>
    @endif

    <form method="POST" action="{{ route('password.update') }}">
      @csrf

      <input type="hidden" name="token" value="{{ $token }}">
      <input type="hidden" name="email" value="{{ $email }}">

      <p class="input-label">New Password</p>
      <div class="password-wrapper">
        <input type="password" id="newPassword" name="password" placeholder="Enter new password" autocomplete="new-password" required>
        <i class="fas fa-eye toggle-password" id="toggleNewPassword" data-target="newPassword"></i>
      </div>
      @error('password')
        <small style="color: #d32f2f; display: block; margin-top: 5px;">{{ $message }}</small>
      @enderror

      <p class="input-label">Confirm Password</p>
      <div class="password-wrapper">
        <input type="password" id="confirmPassword" name="password_confirmation" placeholder="Confirm new password" autocomplete="new-password" required>
        <i class="fas fa-eye toggle-password" id="toggleConfirmPassword" data-target="confirmPassword"></i>
      </div>

      <ul class="password-requirements" id="passwordRequirements">
        <li id="reqLength"><i class="fas fa-circle"></i> At least 8 characters</li>
        <li id="reqUpper"><i class="fas fa-circle"></i> One uppercase letter</li>
        <li id="reqNumber"><i class="fas fa-circle"></i> One number</li>
        <li id="reqMatch"><i class="fas fa-circle"></i> Passwords match</li>
      </ul>

      <button type="submit" class="btn-confirm" id="confirmBtn">
        <span id="confirmBtnText">CONFIRM</span>
      </button>
    </form>
  </div>

  <script>
    const newPassword = document.getElementById('newPassword');
    const confirmPassword = document.getElementById('confirmPassword');
    const toggleNewPassword = document.getElementById('toggleNewPassword');
    const toggleConfirmPassword = document.getElementById('toggleConfirmPassword');

    const reqLength = document.getElementById('reqLength');
    const reqUpper = document.getElementById('reqUpper');
    const reqNumber = document.getElementById('reqNumber');
    const reqMatch = document.getElementById('reqMatch');

    // Toggle password visibility
    function setupToggle(toggleEl, inputEl) {
      toggleEl.addEventListener('click', () => {
        const isPassword = inputEl.type === 'password';
        inputEl.type = isPassword ? 'text' : 'password';
        toggleEl.classList.toggle('fa-eye');
        toggleEl.classList.toggle('fa-eye-slash');
      });
    }
    setupToggle(toggleNewPassword, newPassword);
    setupToggle(toggleConfirmPassword, confirmPassword);

    // Live requirement checks
    function setReqState(el, passed) {
      el.classList.toggle('valid', passed);
      const icon = el.querySelector('i');
      icon.classList.toggle('fa-circle', !passed);
      icon.classList.toggle('fa-check-circle', passed);
    }

    function validate() {
      const pass = newPassword.value;
      const confirm = confirmPassword.value;

      const hasLength = pass.length >= 8;
      const hasUpper = /[A-Z]/.test(pass);
      const hasNumber = /\d/.test(pass);
      const matches = pass.length > 0 && pass === confirm;

      setReqState(reqLength, hasLength);
      setReqState(reqUpper, hasUpper);
      setReqState(reqNumber, hasNumber);
      setReqState(reqMatch, matches);

      const allValid = hasLength && hasUpper && hasNumber && matches;
      document.getElementById('confirmBtn').disabled = !allValid;
      return allValid;
    }

    newPassword.addEventListener('input', validate);
    confirmPassword.addEventListener('input', validate);
  </script>

</body>
</html>
