<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>LearnQuest</title>
    <link rel="icon" type="image/svg+xml" href="/assets/images/LearnQuestLogo.svg">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ route('shell.navbar.css') }}">
    <link rel="stylesheet" href="/assets/css/base/guided-tour.css">
    <link rel="stylesheet" href="/assets/css/base/session-lock.css">
    <style>
        .hidden {
            display: none !important;
        }

        label[for="roleSelector"],
        #roleSelector {
            display: none !important;
        }
    </style>
</head>
<body>
    <form id="shellLogoutForm" action="{{ route('logout') }}" method="POST" class="hidden">
        @csrf
    </form>

    {!! $navbarMarkup !!}

    <script>
        window.LQ_HOST_CONFIG = {
            allowRoleSwitch: false,
            initialPage: @json($initialPage),
            logoutFormId: 'shellLogoutForm',
            profileName: @json($user->name),
            email: @json($user->email),
            role: @json($user->role),
            avatarUrl: @json($user->avatarUrl()),
            alertSound: @json($user->wantsAlert('sound')),
            preferences: @json($user->generalPreferences()),
            idleLockMinutes: @json((int) $user->idle_lock_minutes),
        };
    </script>
    <script src="/assets/js/common/guided-tour.js"></script>
    <script src="/assets/js/common/session-lock.js"></script>
    <script src="{{ route('shell.navbar.js') }}"></script>
</body>
</html>
