<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LearnQuest | Choose your role</title>
  <link rel="icon" type="image/svg+xml" href="/assets/images/LearnQuestLogo.svg">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
  <link rel="stylesheet" href="/assets/css/pages/auth/forgot-password.css">
  <style>
    .error-message {
      color: #d32f2f;
      background-color: #ffebee;
      padding: 10px;
      border-radius: 5px;
      margin-bottom: 15px;
      font-size: 14px;
      border-left: 4px solid #d32f2f;
    }

    .google-account {
      margin: 0 0 20px;
      font-size: 13px;
      color: #666;
      word-break: break-word;
    }

    .role-buttons {
      display: flex;
      gap: 15px;
      margin-bottom: 20px;
    }

    .role-button {
      flex: 1;
      padding: 16px 12px;
      border: 2px solid #e0e0e0;
      background-color: #fff;
      color: #1f2937;
      border-radius: 8px;
      cursor: pointer;
      font-size: 14px;
      font-weight: 600;
      display: flex;
      flex-direction: column;
      align-items: center;
      gap: 8px;
      transition: border-color 0.2s, background-color 0.2s;
    }

    .role-button i { font-size: 24px; }

    .role-button:hover { border-color: #4a90e2; }

    .role-button.selected {
      background-color: #4a90e2;
      border-color: #4a90e2;
      color: #000;
    }

    .btn-next:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    @media (max-width: 380px) {
      .role-buttons { flex-direction: column; }
    }
  </style>
  <script src="/assets/js/auth-background.js" defer></script>
</head>
<body>

  <div class="forgot-password-container">
    <h1>I am a...</h1>
    <p class="subtitle">One more step: choose your account type to finish signing up.</p>
    <p class="google-account">
      <i class="fas fa-user-circle"></i> {{ $name }} &middot; {{ $email }}
    </p>

    @error('role')
      <div class="error-message">{{ $message }}</div>
    @enderror

    <form method="POST" action="{{ route('google.role.store') }}">
      @csrf
      <input type="hidden" name="role" id="roleField" value="">

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

      <button type="submit" class="btn-next" id="roleSubmitBtn" disabled>CONTINUE</button>

      <a href="{{ route('login') }}" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Login
      </a>
    </form>
  </div>

  <script>
    const roleField = document.getElementById('roleField');
    const roleSubmitBtn = document.getElementById('roleSubmitBtn');
    const roleButtons = document.querySelectorAll('.role-button');

    roleButtons.forEach(button => {
      button.addEventListener('click', () => {
        roleButtons.forEach(btn => btn.classList.remove('selected'));
        button.classList.add('selected');
        roleField.value = button.dataset.role;
        roleSubmitBtn.disabled = false;
      });
    });
  </script>

</body>
</html>
