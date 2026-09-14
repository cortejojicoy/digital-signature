{{--
    Floating launcher — the button pinned to every panel page, and the
    slide-over it opens.

    Two deliberate choices in here:

    1. The styles are namespaced (.dsig-*) and shipped inline rather than
       written as Tailwind utility classes. A package view cannot rely on the
       host's compiled CSS containing any particular utility — Filament v3
       builds with Tailwind 3 and v4/v5 with Tailwind 4, and a host theme can
       narrow either. A launcher that renders as an invisible or mispositioned
       button would be worse than no launcher, so it carries its own layout.

    2. Open/close is Alpine, data is Livewire. Toggling a drawer should never
       wait for a round trip; loading the queue should never happen on pages
       where nobody opens the drawer. `@entangle` would couple the two.

    3. The button measures its corner before settling into it. A plugin does
       not own the corner it is dropped into — host apps put chat widgets,
       cookie bars and their own FABs there — so the placement pass below
       stacks this button clear of whatever is already pinned, instead of on
       top of it.
--}}
@php
    $settings = $this->settings;
    $count    = $this->count;
    $onLeft   = str_ends_with($settings['position'], '-left');
@endphp

<div
    class="dsig-launcher dsig-launcher--{{ $settings['position'] }}"
    style="--dsig-x: {{ $settings['offsetX'] }}; --dsig-y: {{ $settings['offsetY'] }}; --dsig-z: {{ $settings['zIndex'] }}; --dsig-w: {{ $settings['width'] }}"
    data-dsig-loaded="{{ $this->loaded ? '1' : '0' }}"
    x-data="dsigLauncher(@js($this->placement), @js($this->viewer))"
    x-on:keydown.escape.window="open = false"
    @if ($settings['poll'] > 0) wire:poll.{{ $settings['poll'] }}s.visible @endif
>
    {{--
    The placement pass. Defined as a global factory rather than through
    `alpine:init`, because that event has usually already fired by the time a
    render hook at the end of the body is parsed — a listener registered here
    would simply never run.
--}}
<script>
    window.dsigLauncher = window.dsigLauncher ?? function (config, viewer) {
        return {
            open: false,
            loaded: false,
            config,
            viewer,
            // 'queue' | 'library'. Two tabs rather than two drawers: placing a
            // signature means reading the document and choosing the signature
            // at the same time, and a second overlay to hold the second half
            // of that would be in the way of the first.
            tab: 'queue',
            // Id of the request whose document is open, or null for the list.
            viewing: null,
            // How many slots the last commit signed, while the confirmation
            // that they moved to the Signed tab is still showing.
            justSigned: 0,
            signedNotice: null,
            observer: null,
            timers: [],

            init() {
                this.loaded = this.$el.dataset.dsigLoaded === '1'

                // The document pane is React and talks back in DOM events
                // rather than reaching into Livewire: closing is this
                // component's business, and refreshing the queue is the
                // server's.
                this.onViewerClose = () => { this.viewing = null }
                this.onSigned = (e) => {
                    this.viewing = null
                    // The document leaves the queue the moment it is signed,
                    // which on its own looks like it was thrown away. Say
                    // where it went, and leave the user on the queue so they
                    // can carry on with the next one.
                    this.justSigned = (e.detail?.signed ?? []).length || 1
                    clearTimeout(this.signedNotice)
                    this.signedNotice = setTimeout(() => { this.justSigned = 0 }, 12000)
                    this.$wire.$refresh()
                }
                window.addEventListener('dsig:viewer-close', this.onViewerClose)
                window.addEventListener('dsig:signed', this.onSigned)

                // The library tab's pad is the same React island the Filament
                // field uses, so it announces its export the same way.
                this.onPadExport = (e) => {
                    if (e.detail?.fieldId !== 'dsig-launcher-pad') return
                    this.$wire.set('newSignature', e.detail.png)
                }
                window.addEventListener('sig:exported', this.onPadExport)

                if (! this.config.enabled) return

                this.schedule()

                // Re-measure when the viewport changes, when Livewire swaps
                // the page, and twice more shortly after load: chat widgets
                // and cookie bars routinely mount a second or two late, and a
                // button that was correctly placed at load would otherwise sit
                // under one for the rest of the session.
                this.onResize = () => this.schedule()
                window.addEventListener('resize', this.onResize, { passive: true })
                document.addEventListener('livewire:navigated', this.onResize)
                this.timers.push(setTimeout(() => this.schedule(), 600))
                this.timers.push(setTimeout(() => this.schedule(), 2500))

                // Body-level additions only. Watching the whole subtree would
                // fire on every Livewire render in the app for no benefit.
                if (window.MutationObserver) {
                    this.observer = new MutationObserver(() => this.schedule())
                    this.observer.observe(document.body, { childList: true })
                }
            },

            destroy() {
                window.removeEventListener('resize', this.onResize)
                document.removeEventListener('livewire:navigated', this.onResize)
                window.removeEventListener('dsig:viewer-close', this.onViewerClose)
                window.removeEventListener('dsig:signed', this.onSigned)
                window.removeEventListener('sig:exported', this.onPadExport)
                this.timers.forEach(clearTimeout)
                clearTimeout(this.signedNotice)
                this.observer?.disconnect()
            },

            toggle() {
                this.open = ! this.open

                if (this.open && ! this.loaded) {
                    this.loaded = true
                    this.$wire.loadRequests()
                }
            },

            /**
             * Swap the drawer body for the document behind one request.
             *
             * Used by both the queue and the signed history. Which of the two
             * it is does not matter here: the pane asks the server whether
             * there is anything left to sign, and renders itself accordingly.
             */
            view(requestId) {
                this.viewing = requestId
            },

            url(template, requestId) {
                return template.replace('__ID__', requestId)
            },

            schedule() {
                clearTimeout(this.pending)
                this.pending = setTimeout(() => requestAnimationFrame(() => this.place()), 120)
            },

            /**
             * Walk the button away from the corner until nothing pinned there
             * overlaps it. Each pass measures the real rendered box, so one
             * pass per obstacle is enough and nested widgets resolve in order.
             */
            place() {
                const fab = this.$refs.fab

                if (! fab || this.open) return

                const fromTop = this.config.position.startsWith('top')
                let stack = 0

                for (let pass = 0; pass < 5; pass++) {
                    this.$el.style.setProperty('--dsig-stack', stack + 'px')

                    const box = fab.getBoundingClientRect()
                    const blockers = this.blockers(box)

                    if (! blockers.length) break

                    const needed = Math.max(...blockers.map((rect) => fromTop
                        ? stack + (rect.bottom - box.top) + this.config.gap
                        : stack + (box.bottom - rect.top) + this.config.gap))

                    // A corner crowded past this point is pathological; drifting
                    // into the middle of the screen would be worse than the
                    // overlap we are trying to avoid.
                    const limit = window.innerHeight * 0.6

                    if (needed <= stack + 0.5 || needed > limit) break

                    stack = needed
                }

                this.$el.style.setProperty('--dsig-stack', stack + 'px')
                this.$el.dataset.dsigStack = Math.round(stack)
            },

            /** Fixed or sticky things overlapping the button's box. */
            blockers(box) {
                const found = new Map()
                const points = [
                    [box.left + box.width / 2, box.top + box.height / 2],
                    [box.left + 2, box.top + 2],
                    [box.right - 2, box.top + 2],
                    [box.left + 2, box.bottom - 2],
                    [box.right - 2, box.bottom - 2],
                ]

                for (const [x, y] of points) {
                    for (const el of document.elementsFromPoint(x, y)) {
                        if (this.isBlocker(el)) found.set(el, el.getBoundingClientRect())
                    }
                }

                // Widgets the point test cannot see — ones that render into an
                // iframe, or paint with pointer-events: none — can be named in
                // config and are measured directly.
                for (const selector of this.config.avoid) {
                    document.querySelectorAll(selector).forEach((el) => {
                        if (this.$el.contains(el)) return

                        const rect = el.getBoundingClientRect()
                        const overlaps = rect.width > 0 && rect.height > 0
                            && rect.left < box.right && rect.right > box.left
                            && rect.top < box.bottom && rect.bottom > box.top

                        if (overlaps) found.set(el, rect)
                    })
                }

                return [...found.values()]
            },

            isBlocker(el) {
                if (! el || el === document.body || el === document.documentElement) return false
                if (this.$el.contains(el) || el.contains(this.$el)) return false
                if (this.config.ignore.some((selector) => el.matches?.(selector))) return false
                if (this.config.avoid.some((selector) => el.matches?.(selector))) return true

                const style = getComputedStyle(el)

                if (style.position !== 'fixed' && style.position !== 'sticky') return false
                if (style.pointerEvents === 'none' || style.visibility === 'hidden') return false

                const rect = el.getBoundingClientRect()

                // Tall elements are layout — sidebars, full-height drawers,
                // backdrops. Stacking above one would push the button off the
                // screen, and sitting in front of one is what a floating
                // button is supposed to do. Wide but short elements are the
                // opposite case: topbars and cookie bars, which must be
                // cleared.
                return rect.height > 0 && rect.height <= window.innerHeight * 0.6
            },
        }
    }
</script>

    <style>
        /*
            --dsig-stack is written by the placement pass: the distance this
            button has to move along its corner's axis to clear whatever the
            host app already pinned there. It stays 0 when the corner is free.
        */
        .dsig-launcher { position: fixed; z-index: var(--dsig-z, 40); --dsig-stack: 0px; }
        .dsig-launcher--bottom-right { right: var(--dsig-x); bottom: calc(var(--dsig-y) + var(--dsig-stack)); }
        .dsig-launcher--bottom-left  { left:  var(--dsig-x); bottom: calc(var(--dsig-y) + var(--dsig-stack)); }
        .dsig-launcher--top-right    { right: var(--dsig-x); top:    calc(var(--dsig-y) + var(--dsig-stack)); }
        .dsig-launcher--top-left     { left:  var(--dsig-x); top:    calc(var(--dsig-y) + var(--dsig-stack)); }

        .dsig-fab {
            position: relative;
            display: flex; align-items: center; justify-content: center;
            width: 3.5rem; height: 3.5rem;
            border: 0; border-radius: 9999px; cursor: pointer;
            background: var(--dsig-accent, #18181b); color: #fff;
            box-shadow: 0 10px 15px -3px rgb(0 0 0 / 0.2), 0 4px 6px -4px rgb(0 0 0 / 0.2);
            transition: transform .15s ease, box-shadow .15s ease, filter .15s ease;
        }
        .dsig-fab:hover  { transform: translateY(-1px); filter: brightness(1.1); }
        .dsig-fab:active { transform: translateY(0); }
        .dsig-fab:focus-visible { outline: 2px solid var(--dsig-accent, #18181b); outline-offset: 3px; }
        .dsig-fab svg { width: 1.5rem; height: 1.5rem; }
        .dark .dsig-fab { background: var(--dsig-accent, #f4f4f5); color: #18181b; }

        .dsig-fab__badge {
            position: absolute; top: -.25rem; right: -.25rem;
            min-width: 1.35rem; height: 1.35rem; padding: 0 .3rem;
            display: flex; align-items: center; justify-content: center;
            border-radius: 9999px; background: #dc2626; color: #fff;
            font-size: .7rem; font-weight: 700; line-height: 1;
            box-shadow: 0 0 0 2px #fff;
        }
        .dark .dsig-fab__badge { box-shadow: 0 0 0 2px #18181b; }

        .dsig-backdrop {
            position: fixed; inset: 0; z-index: calc(var(--dsig-z, 40) - 1);
            background: rgb(0 0 0 / 0.3); backdrop-filter: blur(1px);
        }

        .dsig-panel {
            position: fixed; top: 0; bottom: 0; z-index: calc(var(--dsig-z, 40) + 1);
            display: flex; flex-direction: column;
            width: min(var(--dsig-w, 26rem), 100vw);
            background: #fff; color: #09090b;
            box-shadow: -12px 0 32px -12px rgb(0 0 0 / 0.25);
        }
        .dsig-panel--right { right: 0; }
        .dsig-panel--left  { left: 0; box-shadow: 12px 0 32px -12px rgb(0 0 0 / 0.25); }
        .dark .dsig-panel { background: #18181b; color: #fafafa; }

        .dsig-panel__head {
            display: flex; align-items: center; gap: .75rem;
            padding: 1rem 1.25rem;
            border-bottom: 1px solid rgb(0 0 0 / 0.08);
        }
        .dark .dsig-panel__head { border-color: rgb(255 255 255 / 0.1); }
        .dsig-panel__title { font-size: .9375rem; font-weight: 600; margin: 0; }
        .dsig-panel__sub { font-size: .8125rem; opacity: .6; margin: .1rem 0 0; }
        .dsig-panel__close {
            margin-left: auto; border: 0; background: transparent; cursor: pointer;
            color: inherit; opacity: .55; padding: .25rem; border-radius: .375rem;
            display: flex;
        }
        .dsig-panel__close:hover { opacity: 1; background: rgb(0 0 0 / 0.05); }
        .dark .dsig-panel__close:hover { background: rgb(255 255 255 / 0.08); }
        .dsig-panel__close svg { width: 1.25rem; height: 1.25rem; }

        /*
            A flex column so the document pane can claim the full height and
            run its own scroller — a PDF that scrolled the whole drawer would
            take the toolbar and the signature tray off screen exactly when
            they are needed.
        */
        .dsig-panel__body {
            flex: 1; min-height: 0; overflow-y: auto; padding: 1rem 1.25rem;
            display: flex; flex-direction: column;
        }
        .dsig-panel__body > * { min-height: 0; }
        .dsig-panel__doc { flex: 1; min-height: 0; }

        /*
            Alpine's cloak rule, shipped here rather than borrowed. Filament
            defines one, but a host theme that trims its CSS would leave every
            x-cloak element visible for a frame — and this file's whole premise
            is not depending on the host's stylesheet.
        */
        .dsig-launcher [x-cloak] { display: none !important; }
        .dsig-panel__foot {
            border-top: 1px solid rgb(0 0 0 / 0.08);
            padding: .75rem 1.25rem;
            display: flex; flex-wrap: wrap; gap: 1rem;
            font-size: .8125rem;
        }
        .dark .dsig-panel__foot { border-color: rgb(255 255 255 / 0.1); }
        .dsig-panel__foot a { color: inherit; opacity: .7; text-decoration: none; }
        .dsig-panel__foot a:hover { opacity: 1; text-decoration: underline; }

        .dsig-card {
            border: 1px solid rgb(0 0 0 / 0.08); border-radius: .75rem;
            padding: .875rem; margin-bottom: .75rem;
        }
        .dark .dsig-card { border-color: rgb(255 255 255 / 0.1); background: rgb(255 255 255 / 0.03); }
        .dsig-card__title { font-size: .875rem; font-weight: 600; margin: 0; overflow-wrap: anywhere; }
        .dsig-card__meta { font-size: .8125rem; opacity: .65; margin: .15rem 0 0; }
        .dsig-card__warn { font-size: .75rem; color: #b45309; margin: .5rem 0 0; }
        .dark .dsig-card__warn { color: #fbbf24; }
        .dsig-card__actions { display: flex; gap: .5rem; margin-top: .75rem; }

        .dsig-btn {
            border-radius: .5rem; border: 1px solid transparent; cursor: pointer;
            font-size: .8125rem; font-weight: 600; padding: .375rem .75rem;
            background: var(--dsig-accent, #18181b); color: #fff;
        }
        .dark .dsig-btn { background: var(--dsig-accent, #f4f4f5); color: #18181b; }
        .dsig-btn:disabled { opacity: .5; cursor: not-allowed; }
        .dsig-btn--ghost {
            background: transparent; color: inherit; border-color: rgb(0 0 0 / 0.15);
        }
        .dark .dsig-btn--ghost { background: transparent; color: inherit; border-color: rgb(255 255 255 / 0.2); }

        .dsig-empty { text-align: center; padding: 2.5rem 1rem; }
        .dsig-empty svg { width: 2.25rem; height: 2.25rem; opacity: .35; margin: 0 auto .5rem; }
        .dsig-empty p { margin: 0; font-size: .875rem; }
        .dsig-empty p + p { margin-top: .25rem; opacity: .6; }

        .dsig-note {
            border: 1px dashed rgb(0 0 0 / 0.2); border-radius: .75rem;
            padding: .875rem; margin-bottom: 1rem; font-size: .8125rem;
        }
        .dark .dsig-note { border-color: rgb(255 255 255 / 0.25); }
        .dsig-note a { font-weight: 600; color: inherit; }
        .dsig-note--ok {
            border-style: solid; border-color: rgb(16 185 129 / 0.4);
            background: rgb(16 185 129 / 0.08);
        }

        .dsig-skeleton { height: 4.5rem; border-radius: .75rem; margin-bottom: .75rem;
            background: linear-gradient(90deg, rgb(0 0 0 / .05), rgb(0 0 0 / .1), rgb(0 0 0 / .05));
            background-size: 200% 100%; animation: dsig-shimmer 1.2s infinite linear; }
        .dark .dsig-skeleton { background: linear-gradient(90deg, rgb(255 255 255 / .05), rgb(255 255 255 / .12), rgb(255 255 255 / .05)); background-size: 200% 100%; }
        @keyframes dsig-shimmer { from { background-position: 200% 0; } to { background-position: -200% 0; } }

        .dsig-t-enter { transition: transform .2s ease-out, opacity .2s ease-out; }
        .dsig-t-leave { transition: transform .15s ease-in, opacity .15s ease-in; }
        .dsig-t-to    { transform: translateX(0); opacity: 1; }
        .dsig-panel--right.dsig-t-from { transform: translateX(100%); opacity: 0; }
        .dsig-panel--left.dsig-t-from  { transform: translateX(-100%); opacity: 0; }

        @media (prefers-reduced-motion: reduce) {
            .dsig-t-enter, .dsig-t-leave, .dsig-fab { transition: none; }
            .dsig-skeleton { animation: none; }
        }

        /* ── Tabs ──────────────────────────────────────────────────────── */
        .dsig-tabs { display: flex; gap: .25rem; padding: .5rem 1.25rem 0; }
        .dsig-tab {
            border: 0; background: transparent; cursor: pointer; color: inherit;
            font-size: .8125rem; font-weight: 600; padding: .4rem .7rem;
            border-radius: .5rem .5rem 0 0; opacity: .55;
            border-bottom: 2px solid transparent;
        }
        .dsig-tab:hover { opacity: .85; }
        .dsig-tab--on { opacity: 1; border-bottom-color: var(--dsig-accent, #18181b); }
        .dark .dsig-tab--on { border-bottom-color: var(--dsig-accent, #f4f4f5); }
        .dsig-tab__count {
            display: inline-block; margin-left: .35rem; padding: 0 .35rem;
            border-radius: 9999px; background: rgb(0 0 0 / 0.08); font-size: .6875rem;
        }
        .dark .dsig-tab__count { background: rgb(255 255 255 / 0.12); }

        /*
            Day heading in the signed history. Sticky because the whole point
            of the grouping is knowing which day you are looking at, and that
            is exactly what scrolls away first.
        */
        .dsig-daygroup {
            position: sticky; top: -1rem; z-index: 1;
            margin: 0 -1.25rem .5rem; padding: .4rem 1.25rem;
            font-size: .75rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: .03em; opacity: .55; background: #fff;
        }
        .dsig-daygroup:not(:first-child) { margin-top: 1rem; }
        .dark .dsig-daygroup { background: #18181b; }

        /* ── Signature library ─────────────────────────────────────────── */
        .dsig-lib { display: flex; flex-wrap: wrap; gap: .75rem; margin-bottom: 1rem; }
        .dsig-lib__item {
            display: flex; align-items: center; justify-content: center;
            width: 8rem; height: 4.5rem; padding: .35rem;
            border: 1px solid rgb(0 0 0 / 0.1); border-radius: .625rem; background: #fff;
        }
        .dark .dsig-lib__item { border-color: rgb(255 255 255 / 0.12); background: rgb(255 255 255 / 0.04); }
        .dsig-lib__item img { max-width: 100%; max-height: 100%; object-fit: contain; }

        .dsig-form { display: flex; flex-direction: column; gap: .75rem; }
        .dsig-form label { font-size: .8125rem; font-weight: 600; }
        .dsig-input {
            width: 100%; box-sizing: border-box;
            border: 1px solid rgb(0 0 0 / 0.15); border-radius: .5rem;
            padding: .45rem .6rem; font-size: .8125rem; background: #fff; color: inherit;
        }
        .dark .dsig-input { border-color: rgb(255 255 255 / 0.18); background: rgb(255 255 255 / 0.05); }
        .dsig-pad {
            border: 1px solid rgb(0 0 0 / 0.1); border-radius: .625rem; overflow: hidden;
        }
        .dark .dsig-pad { border-color: rgb(255 255 255 / 0.12); }

        /*
            ── Document pane ──────────────────────────────────────────────
            Namespaced and inline like everything else in this file: the React
            island that renders into it is a package component and cannot
            assume the host compiled any particular Tailwind utility.
        */
        .dsig-viewer { display: flex; flex-direction: column; height: 100%; min-height: 0; }
        .dsig-viewer__bar {
            display: flex; align-items: center; gap: .6rem; flex-wrap: wrap;
            padding-bottom: .6rem; border-bottom: 1px solid rgb(0 0 0 / 0.08);
        }
        .dark .dsig-viewer__bar { border-color: rgb(255 255 255 / 0.1); }
        .dsig-viewer__title {
            font-size: .875rem; font-weight: 600; flex: 1; min-width: 0;
            overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
        }
        .dsig-viewer__zoom { display: flex; align-items: center; gap: .35rem; font-size: .75rem; }
        .dsig-viewer__zoom button {
            width: 1.5rem; height: 1.5rem; border-radius: .375rem; cursor: pointer;
            border: 1px solid rgb(0 0 0 / 0.15); background: transparent; color: inherit;
            font-size: .875rem; line-height: 1;
        }
        .dark .dsig-viewer__zoom button { border-color: rgb(255 255 255 / 0.2); }

        .dsig-viewer__stamp {
            font-size: .75rem; font-weight: 600; padding: .3rem .6rem;
            border-radius: .5rem; white-space: nowrap;
            background: rgb(16 185 129 / 0.12); color: rgb(15 118 110);
        }
        .dark .dsig-viewer__stamp { color: rgb(45 212 191); }

        .dsig-viewer__msg { font-size: .8125rem; margin: .6rem 0 0; opacity: .8; }
        .dsig-viewer__msg--warn  { color: #b45309; }
        .dark .dsig-viewer__msg--warn { color: #fbbf24; }
        .dsig-viewer__msg--error { color: #dc2626; }
        .dark .dsig-viewer__msg--error { color: #f87171; }

        .dsig-viewer__pages {
            flex: 1; min-height: 0; overflow: auto; padding: .75rem 0;
            display: flex; flex-direction: column; align-items: center; gap: 1rem;
            background: rgb(0 0 0 / 0.04);
        }
        .dark .dsig-viewer__pages { background: rgb(0 0 0 / 0.25); }
        .dsig-viewer__page {
            position: relative; line-height: 0;
            box-shadow: 0 2px 10px -2px rgb(0 0 0 / 0.3); background: #fff;
        }
        .dsig-viewer__pageno {
            position: absolute; right: .35rem; bottom: .35rem;
            font-size: .625rem; line-height: 1; padding: .15rem .3rem;
            border-radius: .25rem; background: rgb(0 0 0 / 0.45); color: #fff;
        }

        .dsig-viewer__panel { padding: 2rem 1rem; text-align: center; font-size: .875rem; opacity: .7; }
        .dsig-viewer__panel--error { color: #dc2626; opacity: 1; }
        .dsig-viewer__panel--ok    { color: inherit; opacity: 1; }

        .dsig-viewer__tray { padding-top: .75rem; border-top: 1px solid rgb(0 0 0 / 0.08); }
        .dark .dsig-viewer__tray { border-color: rgb(255 255 255 / 0.1); }
        .dsig-viewer__trayhint { display: block; font-size: .75rem; opacity: .65; margin-bottom: .5rem; }
        .dsig-viewer__chips { display: flex; flex-wrap: wrap; gap: .5rem; }
        .dsig-chip {
            width: 6rem; height: 3.5rem; padding: .25rem; cursor: grab;
            display: flex; align-items: center; justify-content: center;
            border: 2px solid rgb(0 0 0 / 0.1); border-radius: .5rem; background: #fff;
            touch-action: none;
        }
        .dsig-chip:active { cursor: grabbing; }
        .dsig-chip--on { border-color: rgb(20 184 166); }
        .dsig-chip img { max-width: 100%; max-height: 100%; object-fit: contain; pointer-events: none; }
        .dark .dsig-chip { background: rgb(255 255 255 / 0.06); border-color: rgb(255 255 255 / 0.12); }

        /*
            Slot picker, shown when one person holds several slots on the same
            document. `--placed` is a state, not a colour choice: it is the
            only signal that a slot already has a signature waiting on it, and
            committing without noticing an unplaced one is the mistake it
            exists to prevent.
        */
        .dsig-viewer__slots { padding: .6rem 0 0; }
        .dsig-slotchip {
            border: 1px solid rgb(0 0 0 / 0.15); border-radius: .5rem;
            background: transparent; color: inherit; cursor: pointer;
            font-size: .75rem; font-weight: 600; padding: .3rem .6rem;
        }
        .dark .dsig-slotchip { border-color: rgb(255 255 255 / 0.2); }
        .dsig-slotchip--on { border-color: rgb(20 184 166); box-shadow: 0 0 0 1px rgb(20 184 166); }
        .dsig-slotchip--placed { color: rgb(15 118 110); }
        .dark .dsig-slotchip--placed { color: rgb(45 212 191); }
        .dsig-slotchip:disabled { opacity: .45; cursor: not-allowed; }

        /*
            Carries the whole composed stamp now, not just the ink, so it is
            a positioned container rather than an image.
        */
        .dsig-viewer__caption-side {
            display: flex; align-items: center; gap: .3rem;
            margin-bottom: .5rem; font-size: .75rem; opacity: .8;
        }
        .dsig-sidechip {
            width: 1.5rem; height: 1.5rem; cursor: pointer; line-height: 1;
            border: 1px solid rgb(0 0 0 / 0.18); border-radius: .375rem;
            background: transparent; color: inherit; font-size: .8125rem;
        }
        .dark .dsig-sidechip { border-color: rgb(255 255 255 / 0.22); }
        .dsig-sidechip--on {
            border-color: rgb(20 184 166); color: rgb(15 118 110);
            box-shadow: 0 0 0 1px rgb(20 184 166);
        }
        .dark .dsig-sidechip--on { color: rgb(45 212 191); }

        .dsig-viewer__ghost {
            position: fixed; pointer-events: none; z-index: 2147483647;
            transform: translate(-50%, -50%); opacity: .9;
            filter: drop-shadow(0 4px 6px rgb(0 0 0 / 0.35));
            background: rgb(255 255 255 / 0.75);
            outline: 1px dashed rgb(20 184 166);
        }

        .dsig-linkbtn {
            border: 0; background: transparent; padding: 0; cursor: pointer;
            color: inherit; font: inherit; text-decoration: underline;
        }
        .dsig-linkbtn:disabled { opacity: .5; cursor: not-allowed; text-decoration: none; }

        @media (max-width: 640px) {
            .dsig-panel { width: 100vw; }
        }
    </style>

    @if (! ($settings['hideWhenEmpty'] && $count === 0))
        <button
            type="button"
            class="dsig-fab"
            x-ref="fab"
            @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
            x-on:click="toggle()"
            x-bind:aria-expanded="open.toString()"
            aria-label="{{ $settings['label'] }}{{ $count > 0 ? " — {$count} awaiting your signature" : '' }}"
            title="{{ $settings['label'] }}"
        >
            <x-filament::icon :icon="$settings['icon']" />

            @if ($count > 0)
                <span class="dsig-fab__badge">{{ $count > 99 ? '99+' : $count }}</span>
            @endif
        </button>
    @endif

    <div
        x-show="open"
        x-transition.opacity
        x-on:click="open = false"
        class="dsig-backdrop"
        style="display: none"
        aria-hidden="true"
    ></div>

    <div
        x-show="open"
        x-transition:enter="dsig-t-enter"
        x-transition:enter-start="dsig-t-from"
        x-transition:enter-end="dsig-t-to"
        x-transition:leave="dsig-t-leave"
        x-transition:leave-start="dsig-t-to"
        x-transition:leave-end="dsig-t-from"
        class="dsig-panel dsig-panel--{{ $onLeft ? 'left' : 'right' }}"
        style="display: none"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $settings['label'] }}"
    >
        <div class="dsig-panel__head">
            <div>
                <p class="dsig-panel__title">{{ $settings['label'] }}</p>
                <p class="dsig-panel__sub">
                    @if ($count === 0)
                        Nothing awaiting your signature
                    @elseif ($count === 1)
                        1 document awaiting your signature
                    @else
                        {{ $count }} documents awaiting your signature
                    @endif
                </p>
            </div>

            <button type="button" class="dsig-panel__close" x-on:click="open = false" aria-label="Close">
                <x-filament::icon icon="heroicon-o-x-mark" />
            </button>
        </div>

        {{--
            Tabs, not a second drawer. Placing a signature means reading the
            document and picking the signature at the same time, so the library
            has to be reachable from the same overlay rather than behind it.
            Hidden while a document is open: the pane has its own tray.
        --}}
        <div class="dsig-tabs" role="tablist" x-show="! viewing">
            <button
                type="button"
                role="tab"
                class="dsig-tab"
                x-bind:class="tab === 'queue' ? 'dsig-tab--on' : ''"
                x-bind:aria-selected="(tab === 'queue').toString()"
                x-on:click="tab = 'queue'"
                @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
            >
                Awaiting
                @if ($count > 0)
                    <span class="dsig-tab__count">{{ $count > 99 ? '99+' : $count }}</span>
                @endif
            </button>

            <button
                type="button"
                role="tab"
                class="dsig-tab"
                x-bind:class="tab === 'signed' ? 'dsig-tab--on' : ''"
                x-bind:aria-selected="(tab === 'signed').toString()"
                x-on:click="tab = 'signed'"
                @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
            >
                Signed
            </button>

            <button
                type="button"
                role="tab"
                class="dsig-tab"
                x-bind:class="tab === 'library' ? 'dsig-tab--on' : ''"
                x-bind:aria-selected="(tab === 'library').toString()"
                x-on:click="tab = 'library'"
                @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
            >
                My signatures
            </button>
        </div>

        <div class="dsig-panel__body">
            {{--
                The document pane. wire:ignore because the React island inside
                owns this subtree: a Livewire re-render that morphed it would
                tear down a half-placed signature and the rendered PDF with it.
            --}}
            <div wire:ignore x-show="viewing" class="dsig-panel__doc">
                <template x-if="viewing">
                    <div
                        data-dsig-pdf-viewer
                        style="height: 100%"
                        x-bind:data-request-id="viewing"
                        x-bind:data-meta-url="url(viewer.metaUrlTemplate, viewing)"
                        x-bind:data-sign-url="url(viewer.signUrlTemplate, viewing)"
                        x-bind:data-bundle-src="viewer.bundleSrc"
                        x-bind:data-worker-src="viewer.workerSrc"
                        data-csrf-token="{{ csrf_token() }}"
                    ></div>
                </template>
            </div>

            <div x-show="! viewing && tab === 'queue'">
                <div class="dsig-note dsig-note--ok" x-show="justSigned" x-cloak>
                    <span x-text="justSigned === 1
                        ? 'Signed. The document has moved to your Signed tab.'
                        : justSigned + ' slots signed. The document has moved to your Signed tab.'"></span>
                    <a href="#" x-on:click.prevent="tab = 'signed'; justSigned = 0">View it →</a>
                </div>

                @if (! $this->loaded)
                    <div class="dsig-skeleton"></div>
                    <div class="dsig-skeleton"></div>
                    <div class="dsig-skeleton"></div>
                @else
                    @php
                        $requests   = $this->requests;
                        $signatures = $this->signatures;
                    @endphp

                    @if ($signatures->isEmpty())
                        <div class="dsig-note">
                            You have no registered signature yet, so documents can reach you but
                            you can't sign them.
                            <a href="#" x-on:click.prevent="tab = 'library'">Add one now →</a>
                        </div>
                    @endif

                    @forelse ($requests as $request)
                        @php
                            $session  = $request->session;
                            $document = $session?->signable;
                            $title    = $document && method_exists($document, 'getSignableTitle')
                                ? $document->getSignableTitle()
                                : ($session?->template_key ?? 'Document');
                            $blocked  = $session?->isSequential()
                                && $session->requests
                                    ->where('required', true)
                                    ->where('sequence', '<', $request->sequence)
                                    ->contains(fn ($r) => ! $r->isSigned());
                        @endphp

                        <div class="dsig-card" wire:key="dsig-request-{{ $request->id }}">
                            <p class="dsig-card__title">{{ $title }}</p>
                            <p class="dsig-card__meta">
                                You are listed as <strong>{{ $request->role }}</strong>
                                @if ($session?->isSequential())
                                    · step {{ $request->sequence }}
                                @endif
                                @if ($request->requested_at)
                                    · requested {{ $request->requested_at->diffForHumans() }}
                                @endif
                            </p>

                            @if ($blocked)
                                <p class="dsig-card__warn">An earlier signatory must sign before you can.</p>
                            @endif

                            <div class="dsig-card__actions">
                                {{--
                                    Opening the document is the only route to a
                                    signature now. The button it replaced signed
                                    a PDF the signatory had never seen.
                                --}}
                                <button
                                    type="button"
                                    class="dsig-btn"
                                    @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
                                    x-on:click="view({{ $request->id }})"
                                    @disabled($blocked || $signatures->isEmpty())
                                >
                                    View &amp; sign
                                </button>

                                <button
                                    type="button"
                                    class="dsig-btn dsig-btn--ghost"
                                    wire:click="declineRequest({{ $request->id }})"
                                    wire:confirm="Decline to sign this document?"
                                >
                                    Decline
                                </button>
                            </div>
                        </div>
                    @empty
                        <div class="dsig-empty">
                            <x-filament::icon icon="heroicon-o-check-circle" />
                            <p>Nothing waiting on you</p>
                            <p>Documents needing your signature will appear here.</p>
                        </div>
                    @endforelse
                @endif
            </div>

            {{--
                Signed history. A document does not stop being the signatory's
                business the moment they sign it: their certificate is on it,
                and "which of these did I sign, and when?" is a question they
                should be able to answer without asking whoever sent it.

                Grouped by day because signing happens in bursts and the date
                is what people actually remember. Read-only throughout — these
                open in the same document pane with its signing surface absent.
            --}}
            <div x-show="! viewing && tab === 'signed'" x-cloak>
                @if (! $this->loaded)
                    <div class="dsig-skeleton"></div>
                    <div class="dsig-skeleton"></div>
                @else
                    @php $history = $this->signedHistory; @endphp

                    @forelse ($history as $group)
                        <p class="dsig-daygroup">{{ $group['label'] }}</p>

                        @foreach ($group['requests'] as $request)
                            @php
                                $session  = $request->session;
                                $document = $session?->signable;
                                $title    = $document && method_exists($document, 'getSignableTitle')
                                    ? $document->getSignableTitle()
                                    : ($session?->template_key ?? 'Document');
                            @endphp

                            <div class="dsig-card" wire:key="dsig-signed-{{ $request->id }}">
                                <p class="dsig-card__title">{{ $title }}</p>
                                <p class="dsig-card__meta">
                                    Signed as <strong>{{ $request->role }}</strong>
                                    @if ($request->responded_at)
                                        · {{ $request->responded_at->format('g:i a') }}
                                    @endif
                                    @if ($session && ! $session->isComplete())
                                        · awaiting others
                                    @endif
                                </p>

                                <div class="dsig-card__actions">
                                    <button
                                        type="button"
                                        class="dsig-btn dsig-btn--ghost"
                                        x-on:click="view({{ $request->id }})"
                                    >
                                        View document
                                    </button>
                                </div>
                            </div>
                        @endforeach
                    @empty
                        <div class="dsig-empty">
                            <x-filament::icon icon="heroicon-o-document-check" />
                            <p>Nothing signed yet</p>
                            <p>Documents you sign will be kept here.</p>
                        </div>
                    @endforelse
                @endif
            </div>

            <div x-show="! viewing && tab === 'library'" x-cloak>
                @if (! $this->loaded)
                    <div class="dsig-skeleton"></div>
                @else
                    @php $signatures = $this->signatures; @endphp

                    @if ($signatures->isNotEmpty())
                        <div class="dsig-lib">
                            @foreach ($signatures as $signature)
                                <div class="dsig-lib__item" wire:key="dsig-sig-{{ $signature->id }}">
                                    <img src="{{ $signature->getTemporaryImageUrl() }}" alt="Stored signature" />
                                </div>
                            @endforeach
                        </div>
                    @endif

                    @if ($this->canRegisterSignature())
                        <div class="dsig-form">
                            <label for="dsig-launcher-cert">Add a signature</label>

                            {{--
                                The same React pad the Filament field mounts —
                                same island, same export event — so the drawer
                                cannot drift from the resource page's capture.
                                wire:ignore for the same reason as the viewer.
                            --}}
                            <div class="dsig-pad" wire:ignore>
                                <div
                                    data-signature-canvas
                                    data-field-id="dsig-launcher-pad"
                                    data-canvas-width="520"
                                    data-canvas-height="170"
                                    data-confirm-label="Use this signature"
                                    style="height: 218px;"
                                ></div>
                            </div>

                            <p class="dsig-card__meta" x-show="$wire.newSignature" x-cloak>
                                Signature captured.
                            </p>

                            <label for="dsig-launcher-cert">Certificate password</label>
                            <input
                                id="dsig-launcher-cert"
                                type="password"
                                class="dsig-input"
                                autocomplete="new-password"
                                placeholder="Protects your signing certificate"
                                wire:model="newCertificatePassword"
                            />

                            <div>
                                <button
                                    type="button"
                                    class="dsig-btn"
                                    @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
                                    wire:click="createSignature"
                                    wire:loading.attr="disabled"
                                    wire:target="createSignature"
                                >
                                    <span wire:loading.remove wire:target="createSignature">Save signature</span>
                                    <span wire:loading wire:target="createSignature">Saving…</span>
                                </button>
                            </div>
                        </div>
                    @else
                        <div class="dsig-note">
                            You already have an active signature. Revoke it from the Signatures
                            page before registering another.
                        </div>
                    @endif
                @endif
            </div>
        </div>

        {{--
            No link to the full-page inbox. The drawer is the queue now — it
            reads the document, places the signature and keeps the history —
            so a second door to the same room was offering a worse version of
            what the user is already looking at. The page stays routable for
            hosts that want it in their navigation.
        --}}
        @if ($this->libraryUrl)
            <div class="dsig-panel__foot" x-show="! viewing">
                <a href="{{ $this->libraryUrl }}">Manage signatures</a>
            </div>
        @endif
    </div>
</div>
