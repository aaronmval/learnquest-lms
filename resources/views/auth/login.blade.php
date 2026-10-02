<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LearnQuest | Account</title>
  <link rel="icon" type="image/svg+xml" href="/assets/images/LearnQuestLogo.svg">

  <link rel="stylesheet"
        href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">

  <link rel="stylesheet" href="/assets/css/pages/auth/login-page.css">
  <style>
    /* Role Selection Modal Styles */
    .role-modal {
      display: none;
      position: fixed;
      z-index: 1000;
      left: 0;
      top: 0;
      width: 100%;
      height: 100%;
      background-color: rgba(0, 0, 0, 0.5);
      animation: fadeIn 0.3s ease-in;
    }

    .role-modal.show {
      display: flex;
      justify-content: center;
      align-items: center;
    }

    .role-modal-content {
      background-color: white;
      padding: 40px;
      border-radius: 15px;
      text-align: center;
      width: calc(100% - 32px);
      max-width: 400px;
      max-height: calc(100vh - 32px);
      overflow-y: auto;
      box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
      animation: slideUp 0.3s ease-out;
    }

    @media (max-width: 480px) {
      .role-modal-content {
        padding: 28px 20px;
      }

      .role-button,
      .role-modal-buttons button {
        padding: 12px 10px;
        letter-spacing: 0;
      }
    }

    .role-modal-content h2 {
      color: #333;
      margin-bottom: 20px;
      font-size: 24px;
      font-weight: 600;
    }

    .role-modal-content p {
      color: #666;
      margin-bottom: 30px;
      font-size: 14px;
    }

    .role-buttons {
      display: flex;
      gap: 15px;
      justify-content: center;
    }

    .role-button {
      flex: 1;
      padding: 12px 20px;
      border: 2px solid #e0e0e0;
      background-color: white;
      color: #1f2937;
      border-radius: 8px;
      cursor: pointer;
      font-size: 14px;
      font-weight: 600;
      transition: all 0.3s ease;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
    }

    .role-button:hover {
      border-color: #4a90e2;
      color: #000;
    }

    .role-button.selected {
      background-color: #4a90e2;
      color: #000;
      border-color: #4a90e2;
    }

    .role-button i {
      font-size: 24px;
      color: inherit;
    }

    .role-button.selected i {
      color: #000;
    }

    .role-modal-buttons {
      display: flex;
      gap: 10px;
      margin-top: 30px;
    }

    .role-modal-buttons button {
      flex: 1;
      padding: 10px;
      border: none;
      border-radius: 5px;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.3s ease;
    }

    .btn-cancel {
      background-color: #f0f0f0;
      color: #333;
    }

    .btn-cancel:hover {
      background-color: #e0e0e0;
    }

    .btn-confirm {
      background-color: #4a90e2;
      color: white;
    }

    .btn-confirm:hover {
      background-color: #357abd;
    }

    .btn-confirm:disabled {
      background-color: #ccc;
      cursor: not-allowed;
    }

    @keyframes fadeIn {
      from {
        opacity: 0;
      }
      to {
        opacity: 1;
      }
    }

    @keyframes slideUp {
      from {
        transform: translateY(30px);
        opacity: 0;
      }
      to {
        transform: translateY(0);
        opacity: 1;
      }
    }

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

    /* Google sign-in button and its "or" divider */
    .google-divider {
      display: flex;
      align-items: center;
      gap: 10px;
      width: 100%;
      margin: 12px 0 10px;
      color: #9ca3af;
      font-size: 12px;
      text-transform: uppercase;
      letter-spacing: 1px;
    }

    .google-divider::before,
    .google-divider::after {
      content: '';
      flex: 1;
      height: 1px;
      background-color: #e0e0e0;
    }

    .google-btn {
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 10px;
      width: 100%;
      padding: 10px 14px;
      border: 1.5px solid #e0e0e0;
      border-radius: 8px;
      background-color: #fff;
      color: #1f2937;
      font-size: 13px;
      font-weight: 600;
      text-decoration: none;
      transition: background-color 0.2s, border-color 0.2s;
    }

    .google-btn:hover {
      background-color: #f8fafc;
      border-color: #4a90e2;
    }

    .google-btn:focus-visible {
      outline: 2px solid #4a90e2;
      outline-offset: 2px;
    }

    .google-btn svg { flex-shrink: 0; }
  </style>
  <script src="/assets/js/auth-background.js" defer></script>
</head>

<body>

<div class="container" id="container">

  <!-- LOGIN -->
  <div class="form-container sign-in-container">
    <form method="POST" action="{{ route('login') }}">
      @csrf
      <h1><i class="fas fa-graduation-cap"></i> Log In</h1>

      <p>
        Welcome back, Learner!<br>
        Ready to level up?
      </p>

      @if ($errors->getBag('login')->any())
        <div class="error-message show">{{ $errors->getBag('login')->first() }}</div>
      @endif

      <input type="email" name="email" placeholder="EMAIL" value="{{ old('email') }}" required>
      @error('email', 'login')
        <small class="error-text">{{ $message }}</small>
      @enderror

      <div class="password-wrapper">
        <input type="password"
               id="loginPassword"
               name="password"
               placeholder="PASSWORD"
               required>

        <i class="fas fa-eye-slash toggle-password"
           data-target="loginPassword"></i>
      </div>

      <label class="remember">
        <input type="checkbox" name="remember" value="on">
        Remember Me
      </label>

      <button type="submit">LOG IN</button>

      <a href="{{ route('password.request') }}" class="forgot-password-link">
        Forgot Password?
      </a>

      @include('auth.partials.google-button')
    </form>
  </div>

  <!-- SIGN UP -->
  <div class="form-container sign-up-container">
    <form method="POST" action="{{ route('register') }}" id="signupForm">
      @csrf

      <h1>Sign Up</h1>

      <p>
        Create Your Learning Account<br>
        Start your journey in LearnQuest LMS.
      </p>

      @if ($errors->getBag('register')->any())
        <div class="error-message show">{{ $errors->getBag('register')->first() }}</div>
      @endif

      <input type="text" name="name" placeholder="FULL NAME" value="{{ old('name') }}" required>
      @error('name', 'register')
        <small class="error-text">{{ $message }}</small>
      @enderror

      <input type="email" name="email" placeholder="EMAIL ADDRESS" value="{{ old('email') }}" required>
      @error('email', 'register')
        <small class="error-text">{{ $message }}</small>
      @enderror

      <div class="password-wrapper">
        <input type="password"
               id="signupPassword"
               name="password"
               placeholder="PASSWORD"
               minlength="8"
               required>

        <i class="fas fa-eye-slash toggle-password"
           data-target="signupPassword"></i>
      </div>

      <div class="password-wrapper">
        <input type="password"
               id="confirmPassword"
               name="password_confirmation"
               placeholder="CONFIRM PASSWORD"
               minlength="8"
               required>

        <i class="fas fa-eye-slash toggle-password"
           data-target="confirmPassword"></i>
      </div>

      @error('password', 'register')
        <small class="error-text">{{ $message }}</small>
      @enderror

      <!-- Hidden role field -->
      <input type="hidden" name="role" id="roleField" value="">

      <button type="button" id="signupSubmitBtn">SIGN UP</button>
    </form>
  </div>

  <!-- OVERLAY -->
  <div class="overlay-container">
    <div class="overlay">

      <div class="overlay-panel overlay-left">
        <h1>Welcome Back, Learner!</h1>

        <p>
          Ready to continue your quest?
        </p>

        <button class="ghost" id="signIn">
          LOG IN NOW
        </button>

        <br>

        <p class="small-text">
          Do you already have an account?
        </p>
      </div>

      <div class="overlay-panel overlay-right">
        <h1>
          Level Up Your<br>
          Learning Journey!
        </h1>

        <p>
          Join the LearnQuest LMS helps you track progress,
          complete activities, and build your skills through structured lessons
        </p>

        <button class="ghost" id="signUp">
          SIGN UP NOW
        </button>

        <br>

        <p class="small-text">
          Don't have an account yet?
        </p>
      </div>

    </div>
  </div>

</div>

<!-- ROLE SELECTION MODAL -->
<div class="role-modal" id="roleModal">
  <div class="role-modal-content">
    <h2>I am a...</h2>
    <p>Select your account type to continue</p>

    <div class="role-buttons">
      <button type="button" class="role-button" data-role="student">
        <i class="fas fa-user-graduate"></i>
        Student
      </button>
      <button type="button" class="role-button" data-role="professor">
        <i class="fas fa-chalkboard-teacher"></i>
        Teacher
      </button>
    </div>

    <div class="role-modal-buttons">
      <button type="button" class="btn-cancel" id="roleCancelBtn">Cancel</button>
      <button type="button" class="btn-confirm" id="roleConfirmBtn" disabled>Continue</button>
    </div>
  </div>
</div>

<!-- MESSAGE MODAL (replaces the browser's alert for sign-up form checks) -->
<div class="role-modal" id="messageModal" role="alertdialog" aria-modal="true" aria-labelledby="messageModalTitle">
  <div class="role-modal-content">
    <h2 id="messageModalTitle">Check your details</h2>
    <p id="messageModalText"></p>
    <div class="role-modal-buttons">
      <button type="button" class="btn-confirm" id="messageModalOkBtn">OK</button>
    </div>
  </div>
</div>

<script>

  // MESSAGE MODAL
  const messageModal = document.getElementById('messageModal');
  const messageModalText = document.getElementById('messageModalText');
  const messageModalOkBtn = document.getElementById('messageModalOkBtn');
  let messageModalFocusTarget = null;

  function showMessage(message, focusTarget = null) {
    messageModalText.textContent = message;
    messageModalFocusTarget = focusTarget;
    messageModal.classList.add('show');
    messageModalOkBtn.focus();
  }

  function closeMessage() {
    if (!messageModal.classList.contains('show')) return;
    messageModal.classList.remove('show');
    // Put the cursor in the field that needs fixing.
    if (messageModalFocusTarget) messageModalFocusTarget.focus();
    messageModalFocusTarget = null;
  }

  messageModalOkBtn.addEventListener('click', closeMessage);
  messageModal.addEventListener('click', (e) => {
    if (e.target === messageModal) closeMessage();
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') closeMessage();
  });

  const signUpButton = document.getElementById('signUp');
  const signInButton = document.getElementById('signIn');
  const container = document.getElementById('container');

  signUpButton.addEventListener('click', () => {
    container.classList.add('right-panel-active');
  });

  signInButton.addEventListener('click', () => {
    container.classList.remove('right-panel-active');
  });

  @if ($errors->getBag('register')->any() || old('name') || old('email'))
    container.classList.add('right-panel-active');
  @endif

  // Landing page "Sign up" links arrive as /login#signup
  if (location.hash === '#signup') {
    container.classList.add('right-panel-active');
  }

  // PASSWORD TOGGLE
  document.querySelectorAll(".toggle-password").forEach(icon => {
    icon.addEventListener("click", () => {

      const target = document.getElementById(icon.dataset.target);
      if (!target) return;

      target.type = target.type === "password" ? "text" : "password";

      icon.classList.toggle("fa-eye");
      icon.classList.toggle("fa-eye-slash");
    });
  });

  // ROLE SELECTION MODAL
  const roleModal = document.getElementById('roleModal');
  const roleButtons = document.querySelectorAll('.role-button');
  const roleField = document.getElementById('roleField');
  const signupForm = document.getElementById('signupForm');
  const signupSubmitBtn = document.getElementById('signupSubmitBtn');
  const roleConfirmBtn = document.getElementById('roleConfirmBtn');
  const roleCancelBtn = document.getElementById('roleCancelBtn');

  let selectedRole = null;

  roleButtons.forEach(button => {
    button.addEventListener('click', () => {
      roleButtons.forEach(btn => btn.classList.remove('selected'));
      button.classList.add('selected');
      selectedRole = button.dataset.role;
      roleConfirmBtn.disabled = false;
    });
  });

  signupSubmitBtn.addEventListener('click', (e) => {
    e.preventDefault();

    // Validate form before showing modal
    const nameInput = document.querySelector('.sign-up-container input[name="name"]');
    const emailInput = document.querySelector('.sign-up-container input[name="email"]');
    const passwordInput = document.getElementById('signupPassword');
    const confirmInput = document.getElementById('confirmPassword');

    const name = nameInput.value.trim();
    const email = emailInput.value.trim();
    const password = passwordInput.value.trim();
    const confirmPassword = confirmInput.value.trim();

    if (!name || !email || !password || !confirmPassword) {
      const firstEmpty = [nameInput, emailInput, passwordInput, confirmInput].find(input => !input.value.trim());
      showMessage('Please fill in all fields.', firstEmpty);
      return;
    }

    if (password !== confirmPassword) {
      showMessage('The passwords do not match.', confirmInput);
      return;
    }

    if (password.length < 8) {
      showMessage('Your password must be at least 8 characters.', passwordInput);
      return;
    }

    // Show role modal
    roleModal.classList.add('show');
    selectedRole = null;
    roleButtons.forEach(btn => btn.classList.remove('selected'));
    roleConfirmBtn.disabled = true;
  });

  roleConfirmBtn.addEventListener('click', () => {
    if (selectedRole) {
      roleField.value = selectedRole;
      roleModal.classList.remove('show');
      signupForm.submit();
    }
  });

  roleCancelBtn.addEventListener('click', () => {
    roleModal.classList.remove('show');
    selectedRole = null;
    roleButtons.forEach(btn => btn.classList.remove('selected'));
    roleConfirmBtn.disabled = true;
  });

</script>

</body>
</html>
