{{--
    Client mode: under every Filament login form. Local password login stays
    above it for break-glass accounts.
--}}
<div style="margin-top: 1rem; text-align: center;">
    <div style="display: flex; align-items: center; gap: .75rem; margin-bottom: 1rem; font-size: .75rem; opacity: .6;">
        <span style="flex: 1; height: 1px; background: currentColor; opacity: .3;"></span>
        <span>or</span>
        <span style="flex: 1; height: 1px; background: currentColor; opacity: .3;"></span>
    </div>

    <a
        href="{{ $url }}"
        style="display: flex; align-items: center; justify-content: center; gap: .5rem; width: 100%; padding: .55rem 1rem; border-radius: .5rem; border: 1px solid rgb(0 0 0 / .15); font-size: .875rem; font-weight: 600; text-decoration: none; color: inherit;"
    >
        Sign in with UPLB Signature
    </a>
</div>
