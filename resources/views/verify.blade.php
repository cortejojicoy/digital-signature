{{--
    The page a QR on a signed document resolves to.

    Reached by someone holding a printout, on whatever phone they have, very
    likely not signed in. So: no panel chrome, no Livewire, no dependency on
    the host app's compiled CSS — the same reasoning as the launcher, for the
    same reason. A verification page that renders as unstyled text because the
    host trimmed a utility class would undermine the thing it is vouching for.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $valid ? 'Signature verified' : 'Signature not verified' }}</title>
    <style>
        :root { color-scheme: light dark; }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            display: flex; align-items: center; justify-content: center;
            padding: 1.5rem;
            font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f4f4f5; color: #18181b;
        }
        .card {
            width: 100%; max-width: 26rem;
            background: #fff; border-radius: 1rem; padding: 1.75rem;
            box-shadow: 0 10px 30px -12px rgb(0 0 0 / 0.25);
        }
        .mark {
            display: flex; align-items: center; justify-content: center;
            width: 3rem; height: 3rem; border-radius: 9999px;
            font-size: 1.5rem; margin-bottom: 1rem;
        }
        .mark--ok   { background: #d1fae5; color: #047857; }
        .mark--bad  { background: #fee2e2; color: #b91c1c; }
        h1 { font-size: 1.125rem; margin: 0 0 .35rem; }
        .lede { margin: 0 0 1.25rem; color: #52525b; font-size: .9375rem; }
        dl { margin: 0; display: grid; grid-template-columns: auto 1fr; gap: .5rem 1rem; }
        dt { color: #71717a; font-size: .8125rem; }
        dd { margin: 0; font-size: .875rem; font-weight: 600; overflow-wrap: anywhere; }
        .note {
            margin: 1.25rem 0 0; padding-top: 1rem;
            border-top: 1px solid rgb(0 0 0 / 0.08);
            font-size: .8125rem; color: #71717a;
        }
        @media (prefers-color-scheme: dark) {
            body { background: #09090b; color: #fafafa; }
            .card { background: #18181b; }
            .lede, dt, .note { color: #a1a1aa; }
            .note { border-color: rgb(255 255 255 / 0.1); }
            .mark--ok  { background: rgb(16 185 129 / 0.15); color: #34d399; }
            .mark--bad { background: rgb(239 68 68 / 0.15);  color: #f87171; }
        }
    </style>
</head>
<body>
    <main class="card">
        @if ($valid)
            <div class="mark mark--ok" aria-hidden="true">✓</div>
            <h1>Signature verified</h1>
            <p class="lede">This signature was issued by {{ config('app.name') }}.</p>

            <dl>
                <dt>Signed by</dt>
                <dd>{{ $signer ?? 'Unknown' }}</dd>

                @if ($role)
                    <dt>As</dt>
                    <dd>{{ $role }}</dd>
                @endif

                @if ($signedAt)
                    <dt>Signed</dt>
                    <dd>{{ \Illuminate\Support\Carbon::parse($signedAt)->format('j F Y, g:i a') }}</dd>
                @endif

                <dt>Reference</dt>
                <dd>{{ $reference }}</dd>

                @if ($complete !== null)
                    <dt>Document</dt>
                    <dd>{{ $complete ? 'All signatories have signed' : 'Awaiting other signatories' }}</dd>
                @endif
            </dl>

            <p class="note">
                This confirms who made this mark and when. It does not vouch for
                anything printed around it — check that the reference above matches
                the one beside the signature on your copy.
            </p>
        @else
            <div class="mark mark--bad" aria-hidden="true">!</div>
            <h1>Signature not verified</h1>
            <p class="lede">
                No valid signature matches reference <strong>{{ $reference }}</strong>.
            </p>
            <p class="note">
                It may have been revoked, or the code may not have scanned cleanly.
                Check the reference printed beside the signature and try again.
            </p>
        @endif
    </main>
</body>
</html>
