{{--
    "Drop a PDF here": verify a whole signed document.

    Same constraints as verify.blade.php next door, for the same people: an
    auditor or a receiving office, probably not signed in, possibly on a
    phone. So no panel chrome, no Livewire, no dependency on the host app's
    CSS. The file is read and checked in the browser by
    resources/js/verify-entry.js; only its fingerprint leaves this page.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Verify a signed document · {{ config('app.name') }}</title>
    <style>
        :root {
            color-scheme: light dark;
            --bg: #f4f4f5; --card: #ffffff; --fg: #18181b; --muted: #52525b; --faint: #71717a;
            --line: rgb(0 0 0 / 0.09); --drop: #fafafa; --focus: #2563eb;
            --ok: #047857; --ok-bg: #d1fae5;
            --warn: #a16207; --warn-bg: #fef3c7;
            --bad: #b91c1c; --bad-bg: #fee2e2;
            --info: #3f3f46; --info-bg: #e4e4e7;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #09090b; --card: #18181b; --fg: #fafafa; --muted: #a1a1aa; --faint: #a1a1aa;
                --line: rgb(255 255 255 / 0.1); --drop: #1f1f23; --focus: #60a5fa;
                --ok: #34d399; --ok-bg: rgb(16 185 129 / 0.15);
                --warn: #fbbf24; --warn-bg: rgb(245 158 11 / 0.15);
                --bad: #f87171; --bad-bg: rgb(239 68 68 / 0.15);
                --info: #d4d4d8; --info-bg: rgb(255 255 255 / 0.08);
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; min-height: 100vh;
            display: flex; justify-content: center;
            padding: 2rem 1rem;
            font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif;
            background: var(--bg); color: var(--fg);
        }
        main { width: 100%; max-width: 40rem; display: grid; gap: 1rem; align-content: start; }
        .card { background: var(--card); border-radius: 1rem; padding: 1.5rem; box-shadow: 0 10px 30px -12px rgb(0 0 0 / 0.25); min-width: 0; }
        .issuer { margin: 0; font-size: .8125rem; color: var(--faint); letter-spacing: .02em; }
        h1 { font-size: 1.375rem; margin: .25rem 0 .35rem; line-height: 1.25; }
        h2 { font-size: 1.125rem; margin: 0 0 .25rem; line-height: 1.3; }
        h3 { font-size: .8125rem; margin: 1.25rem 0 .5rem; color: var(--faint); text-transform: uppercase; letter-spacing: .06em; }
        p { margin: 0; }
        .lede { color: var(--muted); font-size: .9375rem; }

        .drop {
            display: grid; justify-items: center; gap: .5rem; text-align: center;
            margin-top: 1.25rem; padding: 2rem 1rem;
            border: 2px dashed var(--line); border-radius: .875rem; background: var(--drop);
            cursor: pointer;
        }
        .drop.is-over { border-color: var(--focus); }
        .drop:focus-within { outline: 2px solid var(--focus); outline-offset: 2px; }
        .drop strong { font-size: 1rem; }
        .drop span { font-size: .875rem; color: var(--muted); }
        .drop input { position: absolute; width: 1px; height: 1px; opacity: 0; }
        .privacy { margin-top: .75rem; font-size: .8125rem; color: var(--faint); }

        .banner { display: grid; grid-template-columns: auto minmax(0, 1fr); gap: .25rem 1rem; align-items: start; }
        .mark {
            grid-row: span 2;
            display: flex; align-items: center; justify-content: center;
            width: 2.75rem; height: 2.75rem; border-radius: 9999px; font-size: 1.35rem; font-weight: 700;
        }
        .tone-ok .mark   { background: var(--ok-bg);   color: var(--ok); }
        .tone-warn .mark { background: var(--warn-bg); color: var(--warn); }
        .tone-bad .mark  { background: var(--bad-bg);  color: var(--bad); }
        .tone-info .mark { background: var(--info-bg); color: var(--info); }

        .file { margin-top: 1rem; font-size: .8125rem; color: var(--faint); overflow-wrap: anywhere; }
        dl { margin: 0; display: grid; grid-template-columns: minmax(8rem, auto) minmax(0, 1fr); gap: .4rem 1rem; }
        dt { color: var(--faint); font-size: .8125rem; }
        dd { margin: 0; font-size: .875rem; overflow-wrap: anywhere; }

        ol.slots { list-style: none; margin: 0; padding: 0; display: grid; gap: .5rem; }
        ol.slots li {
            display: grid; grid-template-columns: minmax(7rem, 9rem) minmax(0, 1fr); gap: .1rem 1rem;
            padding: .65rem .75rem; border: 1px solid var(--line); border-radius: .625rem;
        }
        .slot-label { font-weight: 600; font-size: .875rem; }
        .slot-state { font-size: .875rem; }
        .slot-meta { grid-column: 2; font-size: .8125rem; color: var(--faint); }
        .chip { display: inline-block; margin-left: .35rem; padding: 0 .45rem; border-radius: 9999px; font-size: .75rem; font-weight: 600; }
        .chip-ok   { background: var(--ok-bg);   color: var(--ok); }
        .chip-warn { background: var(--warn-bg); color: var(--warn); }
        .chip-bad  { background: var(--bad-bg);  color: var(--bad); }
        .chip-info { background: var(--info-bg); color: var(--info); }

        .actions { margin-top: 1.25rem; display: flex; flex-wrap: wrap; gap: .75rem; align-items: center; }
        button {
            font: inherit; font-size: .875rem; font-weight: 600;
            padding: .5rem 1rem; border-radius: .5rem; border: 1px solid var(--line);
            background: var(--card); color: var(--fg); cursor: pointer;
        }
        button:focus-visible { outline: 2px solid var(--focus); outline-offset: 2px; }
        .note { margin-top: 1rem; padding-top: .9rem; border-top: 1px solid var(--line); font-size: .8125rem; color: var(--faint); }
        .error { color: var(--bad); font-size: .875rem; margin-top: .75rem; }
        @media (max-width: 30rem) {
            dl, ol.slots li { grid-template-columns: 1fr; }
            .slot-meta { grid-column: 1; }
        }
        @media (prefers-reduced-motion: reduce) { * { transition: none !important; } }
    </style>
</head>
<body>
    <main
        id="verify-app"
        data-lookup-url="{{ $lookupUrl }}"
        data-trust-url="{{ $trustUrl }}"
        data-issuer="{{ config('app.name') }}"
    >
        <section class="card" id="verify-intro">
            <p class="issuer">{{ config('app.name') }}</p>
            <h1>Verify a signed document</h1>
            <p class="lede">
                Check that a PDF is a copy this system issued, and see who signed it and when.
            </p>

            <label class="drop" id="verify-drop" for="verify-file">
                <strong>Drop a PDF here, or choose a file</strong>
                <span>PDF files up to 50 MB</span>
                <input type="file" id="verify-file" accept="application/pdf,.pdf">
            </label>
            <p class="privacy">
                The file stays on this device. Only its fingerprint (a SHA-256 hash) is sent to check the record.
            </p>
            <p class="error" id="verify-error" role="alert" hidden></p>
            <noscript>
                <p class="error">This check runs in your browser, so it needs JavaScript turned on.</p>
            </noscript>
        </section>

        <section class="card" id="verify-result" aria-live="polite" hidden></section>
    </main>

    <script src="{{ $scriptUrl }}" defer></script>
</body>
</html>
