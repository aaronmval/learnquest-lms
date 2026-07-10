<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>LearnQuest | Forgot Password</title>
  <link rel="icon" type="image/svg+xml" href="/assets/images/LearnQuestLogo.svg">

  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.3/css/all.min.css">
  <link rel="stylesheet" href="/assets/css/pages/auth/forgot-password.css">
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
</head>
<body>

  <div class="forgot-password-container">
    <h1>Forgot Password</h1>
    <p class="subtitle">You will receive an email with a password reset link</p>

    @if (session('status'))
      <div class="success-message show">
        <i class="fas fa-check-circle"></i> {{ session('status') }}
      </div>
    @endif

    @if ($errors->has('forgot'))
      <div class="error-message show">
        {{ $errors->first('forgot') }}
      </div>
    @endif

    <form method="POST" action="{{ route('password.email') }}">
      @csrf
      <label class="input-label">Enter your Email Address</label>

      <div class="input-wrapper">
        <i class="fas fa-envelope input-icon"></i>
        <input type="email" id="emailInput" name="email" placeholder="EMAIL ADDRESS" value="{{ old('email') }}" required>
      </div>

      @error('email')
        <small style="color: #d32f2f; display: block; margin-top: 5px;">{{ $message }}</small>
      @enderror

      <button type="submit" class="btn-next">NEXT</button>

      <a href="{{ route('login') }}" class="back-link">
        <i class="fas fa-arrow-left"></i> Back to Login
      </a>
    </form>
  </div>

</body>
</html>
