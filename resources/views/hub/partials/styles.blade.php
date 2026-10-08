{{--
    Hub pages' own scoped styles (dsh-*), the same look as the Devices tab's
    dsd-* (signing-devices.blade.php), so they render the same whatever
    Tailwind utilities the hub app's theme happens to compile.
--}}
<style>
    .dsh { --dsh-fg: #18181b; --dsh-muted: #71717a; --dsh-line: rgb(0 0 0 / .08); --dsh-soft: rgb(0 0 0 / .03);
           --dsh-ok: #15803d; --dsh-bad: #b91c1c; --dsh-warn: #b45309; font-size: .875rem; color: var(--dsh-fg); }
    .dark .dsh { --dsh-fg: #f4f4f5; --dsh-muted: #a1a1aa; --dsh-line: rgb(255 255 255 / .1); --dsh-soft: rgb(255 255 255 / .04);
                 --dsh-ok: #4ade80; --dsh-bad: #f87171; --dsh-warn: #fbbf24; }
    .dsh p { margin: 0; }
    .dsh h2 { margin: 0 0 .35rem; font-size: 1rem; font-weight: 600; }
    .dsh h3 { margin: 0 0 .25rem; font-size: .875rem; font-weight: 600; }
    .dsh-stack > * + * { margin-top: 1rem; }
    .dsh-card { border: 1px solid var(--dsh-line); border-radius: .75rem; padding: 1rem; }
    .dsh-meta { color: var(--dsh-muted); font-size: .8125rem; line-height: 1.5; }
    .dsh-meta + .dsh-meta { margin-top: .35rem !important; }
    .dsh-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; overflow-wrap: anywhere; }
    .dsh-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1.75rem; font-weight: 700;
                letter-spacing: .12em; margin: .6rem 0 !important; }
    .dsh-row { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; align-items: center; }
    .dsh-btn { cursor: pointer; border-radius: .5rem; padding: .45rem .8rem; font-size: .8125rem; font-weight: 600;
               border: 1px solid var(--dsh-line); background: transparent; color: inherit; text-decoration: none;
               display: inline-flex; align-items: center; gap: .35rem; }
    .dsh-btn:hover { background: var(--dsh-soft); }
    .dsh-btn[disabled] { opacity: .5; cursor: default; }
    .dsh-btn--primary { background: var(--dsh-fg); border-color: var(--dsh-fg); color: #fff; }
    .dark .dsh-btn--primary { color: #18181b; }
    .dsh-btn--primary:hover { opacity: .9; background: var(--dsh-fg); }
    .dsh-btn--danger { color: var(--dsh-bad); }
    .dsh-btn--block { width: 100%; justify-content: center; padding: .65rem .8rem; font-size: .875rem; }
    .dsh-link { color: inherit; text-decoration: underline; text-underline-offset: 2px; cursor: pointer; background: none; border: 0; padding: 0; font: inherit; }
    .dsh-note { border-radius: .6rem; padding: .6rem .75rem; font-size: .8125rem; line-height: 1.45; }
    .dsh-note--ok { background: rgb(22 163 74 / .1); color: var(--dsh-ok); }
    .dsh-note--bad { background: rgb(220 38 38 / .1); color: var(--dsh-bad); }
    .dsh-note--warn { background: rgb(217 119 6 / .1); color: var(--dsh-warn); }
    .dsh-note--info { background: var(--dsh-soft); border: 1px solid var(--dsh-line); }
    .dsh-badge { display: inline-block; border-radius: 9999px; padding: .05rem .55rem; font-size: .6875rem; font-weight: 600;
                 background: var(--dsh-soft); border: 1px solid var(--dsh-line); white-space: nowrap; }
    .dsh-badge--ok { background: rgb(22 163 74 / .1); border-color: rgb(22 163 74 / .25); color: var(--dsh-ok); }
    .dsh-badge--warn { background: rgb(217 119 6 / .1); border-color: rgb(217 119 6 / .25); color: var(--dsh-warn); }
    .dsh-badge--bad { background: rgb(220 38 38 / .08); border-color: rgb(220 38 38 / .2); color: var(--dsh-bad); }
    .dsh-input, .dsh-select { width: 100%; border-radius: .5rem; border: 1px solid var(--dsh-line); background: transparent;
                              color: inherit; padding: .45rem .6rem; font-size: .875rem; }
    .dsh-select { width: auto; padding: .3rem .5rem; font-size: .8125rem; }
    .dsh-select option, .dsh-select optgroup { color: #18181b; }
    .dsh-field { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin-top: .6rem; font-size: .8125rem; }
    .dsh-list { border: 1px solid var(--dsh-line); border-radius: .75rem; overflow: hidden; }
    .dsh-item { display: flex; gap: .75rem; align-items: center; justify-content: space-between; padding: .7rem .9rem; }
    .dsh-item + .dsh-item { border-top: 1px solid var(--dsh-line); }
    .dsh-item--button { width: 100%; text-align: left; background: transparent; border: 0; color: inherit; cursor: pointer; font: inherit; }
    .dsh-item--button:hover { background: var(--dsh-soft); }
    .dsh-name { font-weight: 600; overflow-wrap: anywhere; }
    .dsh-empty { color: var(--dsh-muted); padding: 1rem .9rem; }
    .dsh-divider { display: flex; align-items: center; gap: .75rem; color: var(--dsh-muted); font-size: .75rem; margin: 1rem 0 !important; }
    .dsh-divider::before, .dsh-divider::after { content: ''; flex: 1; border-top: 1px solid var(--dsh-line); }
    .dsh-grid { display: grid; gap: 1rem; }
    @media (min-width: 768px) { .dsh-grid--2 { grid-template-columns: 1fr 1fr; } }
    .dsh-dl { display: grid; grid-template-columns: max-content 1fr; gap: .25rem .9rem; font-size: .8125rem; margin: .5rem 0 0; }
    .dsh-dl dt { color: var(--dsh-muted); }
    .dsh-dl dd { margin: 0; overflow-wrap: anywhere; }
    .dsh-table { width: 100%; border-collapse: collapse; font-size: .8125rem; }
    .dsh-table th { text-align: left; color: var(--dsh-muted); font-weight: 600; padding: .4rem .5rem; border-bottom: 1px solid var(--dsh-line); }
    .dsh-table td { padding: .45rem .5rem; border-bottom: 1px solid var(--dsh-line); vertical-align: top; }
    .dsh-sigimg { max-height: 90px; max-width: 100%; background: #fff; border-radius: .5rem; border: 1px solid var(--dsh-line); padding: .35rem; }
    .dsh-header { display: flex; flex-wrap: wrap; gap: 1rem; align-items: center; justify-content: space-between; }
    [x-cloak] { display: none !important; }
</style>
