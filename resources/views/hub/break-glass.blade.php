{{--
    Break-glass sign-in (plan 1.7, D10) — see Hub\Identity\Http\Controllers\BreakGlassController.
    A plain page outside both panels, so it works when they can't.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Break-glass sign-in · {{ config('app.name') }}</title>
    @include('signature::hub.partials.styles')
    <style>
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; background: #f4f4f5;
               font-family: ui-sans-serif, system-ui, -apple-system, 'Segoe UI', sans-serif; }
        .dsh-panel { background: #fff; width: min(26rem, calc(100vw - 2rem)); }
        .dsh-panel label { display: block; margin-top: .75rem; }
    </style>
</head>
<body>
    <main class="dsh dsh-card dsh-panel">
        <h2>Break-glass sign-in</h2>
        <p class="dsh-meta">
            For when nobody can sign in with their computer. Admin panel only; every use is recorded and the
            security team is alerted.
        </p>

        @if ($errors->any())
            <div class="dsh-note dsh-note--bad" role="alert" style="margin-top: .75rem;">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('signature.hub.break-glass.store') }}">
            @csrf
            <label class="dsh-meta">Email
                <input class="dsh-input" type="email" name="email" value="{{ old('email') }}" autocomplete="username" required autofocus>
            </label>
            <label class="dsh-meta">Password
                <input class="dsh-input" type="password" name="password" autocomplete="current-password" required>
            </label>
            <label class="dsh-meta">Authenticator code
                <input class="dsh-input dsh-mono" type="text" name="code" inputmode="numeric" pattern="[0-9 ]{6,7}" autocomplete="one-time-code" required>
            </label>
            <div class="dsh-row">
                <button type="submit" class="dsh-btn dsh-btn--primary dsh-btn--block">Sign in to the admin panel</button>
            </div>
        </form>
    </main>
</body>
</html>
