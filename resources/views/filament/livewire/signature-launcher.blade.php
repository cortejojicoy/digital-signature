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

{{--
    The corner class and offsets are rendered for first paint, then owned by
    Alpine (`pos`, `offX`, `offY`) so the Settings tab can move the button
    live. The object form of x-bind:class is what lets Alpine remove the
    server-rendered corner class when the user picks another.
--}}
<div
    class="dsig-launcher dsig-launcher--{{ $settings['position'] }}"
    style="--dsig-x: {{ $settings['offsetX'] }}; --dsig-y: {{ $settings['offsetY'] }}; --dsig-z: {{ $settings['zIndex'] }}; --dsig-w: {{ $settings['width'] }}; --dsig-w-manage: {{ $settings['manageWidth'] }}"
    data-dsig-loaded="{{ $this->loaded ? '1' : '0' }}"
    x-data="dsigLauncher(@js($this->placement), @js($this->viewer), @js($settings['customizable'] ? $this->placementChoice : null))"
    x-bind:class="cornerClasses()"
    x-bind:style="moved ? { '--dsig-x': offX + 'px', '--dsig-y': offY + 'px' } : {}"
    x-on:keydown.escape.window="escape()"
    @if ($settings['poll'] > 0) wire:poll.{{ $settings['poll'] }}s.visible @endif
>
    {{--
    The placement pass. Defined as a global factory rather than through
    `alpine:init`, because that event has usually already fired by the time a
    render hook at the end of the body is parsed — a listener registered here
    would simply never run.
--}}
<script>
    window.dsigLauncher = window.dsigLauncher ?? function (config, viewer, choice) {
        return {
            open: false,
            loaded: false,
            config,
            viewer,
            // Where the button sits. Starts at what the server rendered; the
            // Settings tab changes it in place. `moved` stays false until the
            // user touches a control, so first paint keeps the config's own
            // CSS lengths (which may be rem) instead of a pixel conversion.
            choice,
            pos: config.position,
            offX: choice?.x ?? 0,
            offY: choice?.y ?? 0,
            moved: false,
            // Which edge the drawer slides from. Follows the corner, but only
            // when the drawer opens: moving the button from the Settings tab
            // must not swing the drawer out from under the pointer.
            panelLeft: config.position.endsWith('-left'),
            saveTimer: null,
            saved: false,
            // 'queue' | 'library'. Two tabs rather than two drawers: placing a
            // signature means reading the document and choosing the signature
            // at the same time, and a second overlay to hold the second half
            // of that would be in the way of the first.
            tab: 'queue',
            // Id of the request whose document is open, or null for the list.
            viewing: null,
            // Set while the viewer shows a template preview rather than a request.
            previewMeta: null,
            // 'tabs' | 'manage' | 'signed'. Manage mode widens the drawer and
            // replaces the tabs with the signature list and the selected
            // signature's details — what the View Signature page used to be.
            // Signed mode does the same for "All documents I've signed", which
            // used to be a page of its own.
            mode: 'tabs',
            // Status chip in manage mode's list: 'all' or a signature status.
            manageFilter: 'all',
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
                this.onViewerClose = () => {
                    this.viewing = null
                    this.previewMeta = null
                }
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

                this.openFromLink()

                if (! this.config.enabled) return

                this.schedule()

                // place() stands down while the drawer is open, and the
                // button may have moved corners meanwhile.
                this.$watch('open', (open) => { if (! open) this.schedule() })

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
                clearTimeout(this.saveTimer)
                clearTimeout(this.signedNotice)
                this.observer?.disconnect()
            },

            toggle() {
                if (! this.open) this.panelLeft = this.onLeft

                this.open = ! this.open

                if (this.open && ! this.loaded) {
                    this.loaded = true
                    this.$wire.loadRequests()
                }
            },

            /**
             * `?dsig=manage` or `?dsig=manage:{uuid}` opens the drawer straight
             * into manage mode. Old View Signature URLs redirect here. The
             * parameter is dropped afterwards so a reload does not reopen it.
             */
            openFromLink() {
                const url = new URL(window.location.href)
                const link = url.searchParams.get('dsig') ?? ''

                if (! link.startsWith('manage')) return

                url.searchParams.delete('dsig')
                window.history.replaceState(window.history.state, '', url)

                this.open = true
                this.loaded = true
                this.manage(link.slice('manage:'.length) || null)
            },

            /**
             * Widen the drawer into manage mode. The server owns which
             * signature is selected, because it decides whether the uuid is
             * this user's at all.
             */
            manage(uuid = null) {
                this.viewing = null
                this.mode = 'manage'
                this.loaded = true
                this.$wire.manage(uuid)
            },

            leaveManage() {
                this.mode = 'tabs'
            },

            /** Widen the drawer into the full record of what this user signed. */
            openSigned() {
                this.viewing = null
                this.mode = 'signed'
                this.loaded = true
                this.$wire.openSigned()
            },

            get wide() {
                return this.mode === 'manage' || this.mode === 'signed'
            },

            /** First Escape leaves manage or signed mode; the next closes the drawer. */
            escape() {
                if (this.open && this.viewing && this.mode === 'signed') {
                    this.viewing = null

                    return
                }

                if (this.open && this.wide) {
                    this.leaveManage()

                    return
                }

                this.open = false
            },

            /**
             * Swap the drawer body for the document behind one request.
             *
             * Used by both the queue and the signed history. Which of the two
             * it is does not matter here: the pane asks the server whether
             * there is anything left to sign, and renders itself accordingly.
             */
            view(requestId) {
                // From the signed list, stay in it: closing the document
                // lands back on the list rather than on the tabs.
                if (this.mode !== 'signed') this.mode = 'tabs'
                this.previewMeta = null
                this.viewing = requestId
            },

            /**
             * A template's sample, read-only, from Manage signatures. Stays in
             * manage mode, so closing the viewer lands back on the template list.
             */
            preview(metaUrl) {
                this.previewMeta = metaUrl
                this.viewing = 'preview'
            },

            url(template, requestId) {
                return template.replace('__ID__', requestId)
            },

            cornerClasses() {
                return {
                    'dsig-launcher--bottom-right': this.pos === 'bottom-right',
                    'dsig-launcher--bottom-left':  this.pos === 'bottom-left',
                    'dsig-launcher--top-right':    this.pos === 'top-right',
                    'dsig-launcher--top-left':     this.pos === 'top-left',
                }
            },

            get onLeft() {
                return this.pos.endsWith('-left')
            },

            /**
             * Settings tab: move the button now, save shortly after. Sliders
             * fire on every step, and one save per drag is enough.
             */
            setCorner(position) {
                this.pos = position
                this.moved = true
                this.placementChanged()
            },

            placementChanged() {
                this.moved = true
                this.offX = Math.max(0, Math.min(400, parseInt(this.offX, 10) || 0))
                this.offY = Math.max(0, Math.min(400, parseInt(this.offY, 10) || 0))
                this.config.position = this.pos
                this.saved = false

                // The overlap pass measured the old corner; the new one may
                // have its own neighbours.
                this.$root.style.setProperty('--dsig-stack', '0px')
                this.schedule()

                clearTimeout(this.saveTimer)
                this.saveTimer = setTimeout(() => {
                    this.$wire.saveLauncherPlacement(this.pos, this.offX, this.offY)
                        .then(() => { this.saved = true })
                }, 400)
            },

            resetPlacement() {
                const d = this.choice.default
                this.pos = d.position
                this.offX = d.x
                this.offY = d.y
                this.config.position = this.pos
                this.saved = false
                this.schedule()
                clearTimeout(this.saveTimer)
                this.$wire.resetLauncherPlacement().then(() => { this.saved = true })
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
            transition: width .2s ease;
        }
        .dsig-panel--wide { width: min(var(--dsig-w-manage, 80rem), 100vw); }
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
        .dsig-panel__back {
            border: 0; background: transparent; cursor: pointer; color: inherit;
            opacity: .7; padding: .25rem; border-radius: .375rem; display: flex;
        }
        .dsig-panel__back:hover { opacity: 1; background: rgb(0 0 0 / 0.05); }
        .dark .dsig-panel__back:hover { background: rgb(255 255 255 / 0.08); }
        .dsig-panel__back svg { width: 1.25rem; height: 1.25rem; }

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
        .dsig-panel__foot .dsig-linkbtn { opacity: .7; text-decoration: none; }
        .dsig-panel__foot .dsig-linkbtn:hover { opacity: 1; text-decoration: underline; }

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
            .dsig-t-enter, .dsig-t-leave, .dsig-fab, .dsig-panel { transition: none; }
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

        .dsig-more { display: flex; justify-content: center; margin: .5rem 0 1rem; }

        /* ── All documents I've signed ─────────────────────────────────── */
        .dsig-signed__search { max-width: 24rem; margin-bottom: 1rem; }
        .dsig-signed__row { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem 1rem; }
        .dsig-signed__body { min-width: 0; flex: 1 1 16rem; }

        /* ── Settings: a little screen with a target in each corner ────── */
        .dsig-corners {
            display: grid; grid-template-columns: 1fr 1fr; gap: .5rem;
            max-width: 22rem; padding: .5rem; border-radius: .75rem;
            border: 1px solid rgb(0 0 0 / 0.08); background: rgb(0 0 0 / 0.02);
        }
        .dark .dsig-corners { border-color: rgb(255 255 255 / 0.1); background: rgb(255 255 255 / 0.03); }
        .dsig-corner {
            display: flex; flex-direction: column; gap: .5rem; height: 4.5rem; padding: .5rem;
            border: 1px solid rgb(0 0 0 / 0.1); border-radius: .5rem; cursor: pointer;
            background: #fff; color: inherit; font-size: .75rem; font-weight: 600;
        }
        .dark .dsig-corner { background: rgb(255 255 255 / 0.04); border-color: rgb(255 255 255 / 0.12); }
        .dsig-corner--top-left     { align-items: flex-start; justify-content: flex-start; }
        .dsig-corner--top-right    { align-items: flex-end;   justify-content: flex-start; }
        .dsig-corner--bottom-left  { align-items: flex-start; justify-content: flex-end; flex-direction: column-reverse; }
        .dsig-corner--bottom-right { align-items: flex-end;   justify-content: flex-end; flex-direction: column-reverse; }
        .dsig-corner__dot {
            width: .9rem; height: .9rem; border-radius: 9999px;
            border: 2px solid currentColor; opacity: .35;
        }
        .dsig-corner__label { opacity: .7; }
        .dsig-corner:hover { border-color: rgb(0 0 0 / 0.25); }
        .dark .dsig-corner:hover { border-color: rgb(255 255 255 / 0.3); }
        .dsig-corner--on { border-color: var(--dsig-accent, #18181b); box-shadow: 0 0 0 1px var(--dsig-accent, #18181b); }
        .dark .dsig-corner--on { border-color: var(--dsig-accent, #f4f4f5); box-shadow: 0 0 0 1px var(--dsig-accent, #f4f4f5); }
        .dsig-corner--on .dsig-corner__dot { opacity: 1; background: currentColor; }
        .dsig-corner--on .dsig-corner__label { opacity: 1; }
        .dsig-corner:focus-visible { outline: 2px solid var(--dsig-accent, #18181b); outline-offset: 2px; }
        .dsig-range { width: 100%; max-width: 22rem; accent-color: var(--dsig-accent, #18181b); }
        .dark .dsig-range { accent-color: var(--dsig-accent, #f4f4f5); }
        .dsig-settings__foot { display: flex; align-items: center; gap: .75rem; }

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
            cursor: pointer; transition: border-color .15s ease;
        }
        .dsig-lib__item:hover { border-color: var(--dsig-accent, #18181b); }
        .dark .dsig-lib__item:hover { border-color: var(--dsig-accent, #f4f4f5); }
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
        .dsig-drop {
            display: flex; align-items: center; justify-content: center; text-align: center;
            min-height: 218px; box-sizing: border-box; padding: 1rem; cursor: pointer;
            border: 2px dashed rgb(0 0 0 / 0.15); border-radius: .625rem;
            background: rgb(0 0 0 / 0.02); transition: border-color .15s ease;
        }
        .dsig-form label.dsig-drop { font-weight: 400; }
        .dsig-drop > span, .dsig-drop > span > span { display: block; }
        .dsig-drop:hover, .dsig-drop--on { border-color: var(--dsig-accent, #18181b); }
        .dark .dsig-drop { border-color: rgb(255 255 255 / 0.18); background: rgb(255 255 255 / 0.03); }
        .dark .dsig-drop:hover, .dark .dsig-drop--on { border-color: var(--dsig-accent, #f4f4f5); }
        .dsig-drop__icon { width: 1.75rem; height: 1.75rem; margin: 0 auto .5rem; opacity: .45; }
        .dsig-drop__title { font-size: .875rem; font-weight: 600; }
        .dsig-drop__hint { font-size: .75rem; opacity: .6; margin-top: .25rem; }
        .dsig-drop__preview {
            max-width: 100%; max-height: 8rem; object-fit: contain; margin: 0 auto;
            border-radius: .5rem; background: #fff; padding: .25rem;
        }

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

        /*
            ── Manage signatures ──────────────────────────────────────────
            List and detail side by side. Each pane scrolls on its own, so a
            long list never scrolls the selected signature out of view.
        */
        .dsig-manage-wrap { flex: 1; min-height: 0; display: flex; flex-direction: column; }
        .dsig-manage {
            flex: 1; min-height: 0;
            display: grid; grid-template-columns: minmax(15rem, 20rem) 1fr; gap: 1.25rem;
        }
        .dsig-manage__list, .dsig-manage__detail { min-height: 0; overflow-y: auto; }
        .dsig-manage__list { padding-right: .25rem; }
        .dsig-manage__back { display: none; margin-bottom: .75rem; font-size: .8125rem; }

        .dsig-chips { display: flex; flex-wrap: wrap; gap: .35rem; margin-bottom: .75rem; }
        .dsig-filter {
            border: 1px solid rgb(0 0 0 / 0.15); border-radius: 9999px; cursor: pointer;
            background: transparent; color: inherit; font-size: .75rem; font-weight: 600;
            padding: .2rem .6rem; opacity: .7;
        }
        .dark .dsig-filter { border-color: rgb(255 255 255 / 0.2); }
        .dsig-filter--on { opacity: 1; border-color: var(--dsig-accent, #18181b); box-shadow: 0 0 0 1px var(--dsig-accent, #18181b); }
        .dark .dsig-filter--on { border-color: var(--dsig-accent, #f4f4f5); box-shadow: 0 0 0 1px var(--dsig-accent, #f4f4f5); }

        .dsig-row {
            display: flex; align-items: center; gap: .75rem; width: 100%; text-align: left;
            border: 1px solid rgb(0 0 0 / 0.08); border-radius: .625rem;
            background: transparent; color: inherit; cursor: pointer;
            padding: .5rem; margin-bottom: .5rem;
        }
        .dark .dsig-row { border-color: rgb(255 255 255 / 0.1); }
        .dsig-row:hover { border-color: rgb(0 0 0 / 0.2); }
        .dark .dsig-row:hover { border-color: rgb(255 255 255 / 0.25); }
        .dsig-row--on { border-color: var(--dsig-accent, #18181b); box-shadow: 0 0 0 1px var(--dsig-accent, #18181b); }
        .dark .dsig-row--on { border-color: var(--dsig-accent, #f4f4f5); box-shadow: 0 0 0 1px var(--dsig-accent, #f4f4f5); }
        .dsig-row__thumb {
            flex: none; width: 4.5rem; height: 2.5rem; padding: .15rem;
            display: flex; align-items: center; justify-content: center;
            border-radius: .375rem; background: #fff;
        }
        .dsig-row__thumb img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .dsig-row__body { min-width: 0; flex: 1; }
        .dsig-row__title { display: block; font-size: .8125rem; font-weight: 600; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dsig-row__meta { display: block; font-size: .75rem; opacity: .65; margin: .1rem 0 0; }

        .dsig-badge {
            display: inline-block; font-size: .6875rem; font-weight: 600; line-height: 1.4;
            padding: 0 .4rem; border-radius: .375rem;
            background: rgb(245 158 11 / 0.14); color: rgb(180 83 9);
        }
        .dsig-badge--ok   { background: rgb(16 185 129 / 0.14); color: rgb(4 120 87); }
        .dsig-badge--bad  { background: rgb(220 38 38 / 0.12); color: rgb(185 28 28); }
        .dsig-badge--info { background: rgb(59 130 246 / 0.12); color: rgb(29 78 216); }
        .dark .dsig-badge       { color: rgb(251 191 36); }
        .dark .dsig-badge--ok   { color: rgb(52 211 153); }
        .dark .dsig-badge--bad  { color: rgb(248 113 113); }
        .dark .dsig-badge--info { color: rgb(96 165 250); }

        .dsig-detail__image {
            display: flex; align-items: center; justify-content: center;
            height: 10rem; padding: .75rem; border-radius: .75rem; background: #fff;
            border: 1px solid rgb(0 0 0 / 0.08);
        }
        .dsig-detail__image img { max-width: 100%; max-height: 100%; object-fit: contain; }
        .dsig-detail__actions { display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; margin: .75rem 0 1rem; }
        .dsig-btn--danger { background: #dc2626; color: #fff; }
        .dark .dsig-btn--danger { background: #dc2626; color: #fff; }
        a.dsig-btn { text-decoration: none; display: inline-block; }
        .dsig-confirm { font-size: .8125rem; display: flex; flex-wrap: wrap; align-items: center; gap: .5rem; }

        .dsig-section { margin-bottom: 1.25rem; }
        .dsig-section__title { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; opacity: .55; margin: 0 0 .5rem; }
        .dsig-facts { display: grid; grid-template-columns: repeat(auto-fill, minmax(12rem, 1fr)); gap: .75rem 1rem; margin: 0; }
        .dsig-facts dt { font-size: .75rem; opacity: .6; }
        .dsig-facts dd { margin: .1rem 0 0; font-size: .8125rem; overflow-wrap: anywhere; }
        .dsig-uses { margin: 0; padding-left: 1rem; font-size: .8125rem; }
        .dsig-uses li + li { margin-top: .25rem; }

        .dsig-meta summary { cursor: pointer; font-size: .8125rem; font-weight: 600; }
        .dsig-meta__row { display: flex; align-items: center; gap: .5rem; margin-top: .5rem; font-size: .75rem; }
        .dsig-meta__label { flex: none; width: 11rem; opacity: .6; }
        .dsig-meta__value { min-width: 0; flex: 1; font-family: ui-monospace, SFMono-Regular, Menlo, monospace; overflow-wrap: anywhere; }

        .dsig-templates { display: grid; grid-template-columns: repeat(auto-fill, minmax(13rem, 1fr)); gap: .75rem; }
        .dsig-template {
            border: 1px solid rgb(0 0 0 / 0.08); border-radius: .75rem; overflow: hidden;
            display: flex; flex-direction: column;
        }
        .dark .dsig-template { border-color: rgb(255 255 255 / 0.1); background: rgb(255 255 255 / 0.03); }
        .dsig-template__head { padding: .5rem .75rem; border-bottom: 1px solid rgb(0 0 0 / 0.08); }
        .dark .dsig-template__head { border-color: rgb(255 255 255 / 0.1); }
        .dsig-template__label { font-size: .75rem; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dsig-template__key { font-size: .6875rem; opacity: .6; margin: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .dsig-template__preview { display: block; width: 100%; height: 6rem; padding: .25rem; border: 0; cursor: zoom-in;
                                  background: rgb(0 0 0 / 0.03); }
        .dsig-template__preview:hover { background: rgb(0 0 0 / 0.06); }
        .dsig-template__preview:focus-visible { outline: 2px solid var(--dsig-accent, currentColor); outline-offset: -2px; }
        .dsig-template__preview img { width: 100%; height: 100%; object-fit: contain; }
        .dsig-template__foot { display: flex; align-items: center; justify-content: space-between; gap: .5rem; padding: .5rem .75rem; font-size: .6875rem; }

        @media (max-width: 640px) {
            .dsig-panel, .dsig-panel--wide { width: 100vw; }

            /* One pane at a time: the list, or the signature picked from it. */
            .dsig-manage { grid-template-columns: 1fr; }
            .dsig-manage--picked .dsig-manage__list { display: none; }
            .dsig-manage:not(.dsig-manage--picked) .dsig-manage__detail { display: none; }
            .dsig-manage__back { display: inline-block; }
            .dsig-meta__label { width: 8rem; }
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
        x-bind:class="{ 'dsig-panel--wide': wide, 'dsig-panel--left': panelLeft, 'dsig-panel--right': ! panelLeft }"
        style="display: none"
        role="dialog"
        aria-modal="true"
        aria-label="{{ $settings['label'] }}"
    >
        <div class="dsig-panel__head">
            <button
                type="button"
                class="dsig-panel__back"
                x-show="wide && ! (mode === 'signed' && viewing)"
                x-cloak
                x-on:click="leaveManage()"
                aria-label="Back"
            >
                <x-filament::icon icon="heroicon-o-arrow-left" />
            </button>

            <div>
                <p class="dsig-panel__title">{{ $settings['label'] }}</p>
                <p class="dsig-panel__sub" x-show="mode === 'manage'" x-cloak>Manage signatures</p>
                <p class="dsig-panel__sub" x-show="mode === 'signed'" x-cloak>All documents I've signed</p>
                <p class="dsig-panel__sub" x-show="mode === 'tabs'">
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
        <div class="dsig-tabs" role="tablist" x-show="! viewing && mode === 'tabs'">
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

            <button
                type="button"
                role="tab"
                class="dsig-tab"
                x-bind:class="tab === 'devices' ? 'dsig-tab--on' : ''"
                x-bind:aria-selected="(tab === 'devices').toString()"
                x-on:click="tab = 'devices'"
                @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
            >
                Devices
            </button>

            @if ($settings['customizable'])
                <button
                    type="button"
                    role="tab"
                    class="dsig-tab"
                    x-bind:class="tab === 'settings' ? 'dsig-tab--on' : ''"
                    x-bind:aria-selected="(tab === 'settings').toString()"
                    x-on:click="tab = 'settings'"
                    @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
                >
                    Settings
                </button>
            @endif
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
                        x-bind:data-meta-url="previewMeta ?? url(viewer.metaUrlTemplate, viewing)"
                        x-bind:data-sign-url="url(viewer.signUrlTemplate, viewing)"
                        x-bind:data-bundle-src="viewer.bundleSrc"
                        x-bind:data-worker-src="viewer.workerSrc"
                        data-csrf-token="{{ csrf_token() }}"
                    ></div>
                </template>
            </div>

            <div x-show="! viewing && mode === 'tabs' && tab === 'queue'">
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
            <div x-show="! viewing && mode === 'tabs' && tab === 'signed'" x-cloak>
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
                                    {{-- The copy this signature produced: what they signed, as they signed it. --}}
                                    <button
                                        type="button"
                                        class="dsig-btn dsig-btn--ghost"
                                        x-on:click="view({{ $request->id }})"
                                    >
                                        The copy I signed
                                    </button>

                                    @if ($session?->uuid)
                                        <a
                                            class="dsig-btn dsig-btn--ghost"
                                            href="{{ route('signature.documents.show', ['session' => $session->uuid]) }}"
                                            target="_blank"
                                            rel="noopener"
                                        >
                                            Current
                                        </a>
                                    @endif
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

                    @if ($history !== [])
                        <div class="dsig-more">
                            <button type="button" class="dsig-btn dsig-btn--ghost" x-on:click="openSigned()">
                                All documents I've signed →
                            </button>
                        </div>
                    @endif
                @endif
            </div>

            <div x-show="! viewing && mode === 'tabs' && tab === 'library'" x-cloak>
                @if (! $this->loaded)
                    <div class="dsig-skeleton"></div>
                @else
                    @php $signatures = $this->signatures; @endphp

                    @if ($signatures->isNotEmpty())
                        <div class="dsig-lib">
                            @foreach ($signatures as $signature)
                                <button
                                    type="button"
                                    class="dsig-lib__item"
                                    wire:key="dsig-sig-{{ $signature->id }}"
                                    x-on:click="manage(@js($signature->uuid))"
                                    title="Manage this signature"
                                >
                                    <img src="{{ $signature->getTemporaryImageUrl() }}" alt="Stored signature" />
                                </button>
                            @endforeach
                        </div>
                    @endif

                    @if ($this->canRegisterSignature())
                        {{--
                            Draw or upload, as the Signatures resource's field
                            offers. Both end up in newSignature as a PNG data
                            URL; the upload is normalised to PNG in the browser
                            exactly as signatureField.js does it, so the server
                            sees one format either way.
                        --}}
                        <div
                            class="dsig-form"
                            x-data="{
                                method: 'draw',
                                uploadPreview: null,
                                uploadError: null,
                                uploadLoading: false,
                                dragging: false,
                                maxKb: @js((int) config('signature.image.max_kb', 512)),

                                switchMethod(method) {
                                    if (this.method === method) return
                                    this.method = method
                                    this.uploadPreview = null
                                    this.uploadError = null
                                    window.dispatchEvent(new CustomEvent('sig:clear', { detail: { fieldId: 'dsig-launcher-pad' } }))
                                    this.$wire.set('newSignature', null)
                                },

                                readFile(file) {
                                    if (! file) return
                                    this.uploadError = null

                                    if (! ['image/png', 'image/jpeg'].includes(file.type)) {
                                        this.uploadError = 'Only PNG and JPG files are allowed.'
                                        return
                                    }
                                    if (file.size > this.maxKb * 1024) {
                                        this.uploadError = `File must be smaller than ${this.maxKb} KB.`
                                        return
                                    }

                                    this.uploadLoading = true
                                    const reader = new FileReader()
                                    reader.onerror = () => {
                                        this.uploadError = 'Failed to read file.'
                                        this.uploadLoading = false
                                    }
                                    reader.onload = (e) => {
                                        const img = new Image()
                                        img.onerror = () => {
                                            this.uploadError = 'Could not load image. Try another file.'
                                            this.uploadLoading = false
                                        }
                                        img.onload = () => {
                                            const canvas = document.createElement('canvas')
                                            canvas.width = img.naturalWidth || 600
                                            canvas.height = img.naturalHeight || 200
                                            canvas.getContext('2d').drawImage(img, 0, 0)
                                            const png = canvas.toDataURL('image/png')

                                            this.uploadPreview = png
                                            this.uploadLoading = false
                                            this.$wire.set('newSignature', png)
                                        }
                                        img.src = e.target.result
                                    }
                                    reader.readAsDataURL(file)
                                },
                            }"
                            {{-- A successful save clears newSignature; the preview goes with it. --}}
                            x-effect="if (! $wire.newSignature) uploadPreview = null"
                        >
                            <label for="dsig-launcher-cert">Add a signature</label>

                            <div class="dsig-chips" role="tablist" aria-label="Signature input method" style="margin-bottom: 0">
                                <button
                                    type="button"
                                    role="tab"
                                    class="dsig-filter"
                                    x-bind:class="method === 'draw' ? 'dsig-filter--on' : ''"
                                    x-bind:aria-selected="(method === 'draw').toString()"
                                    x-on:click="switchMethod('draw')"
                                    @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
                                >Draw</button>
                                <button
                                    type="button"
                                    role="tab"
                                    class="dsig-filter"
                                    x-bind:class="method === 'upload' ? 'dsig-filter--on' : ''"
                                    x-bind:aria-selected="(method === 'upload').toString()"
                                    x-on:click="switchMethod('upload')"
                                    @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
                                >Upload</button>
                            </div>

                            {{--
                                The same React pad the Filament field mounts —
                                same island, same export event — so the drawer
                                cannot drift from the resource page's capture.
                                wire:ignore for the same reason as the viewer.
                                Hidden rather than removed on Upload, so React
                                is not torn down and remounted on every switch.
                            --}}
                            <div class="dsig-pad" wire:ignore x-show="method === 'draw'">
                                <div
                                    data-signature-canvas
                                    data-field-id="dsig-launcher-pad"
                                    data-canvas-width="520"
                                    data-canvas-height="170"
                                    data-confirm-label="Use this signature"
                                    style="height: 218px;"
                                ></div>
                            </div>

                            <div x-show="method === 'upload'" x-cloak>
                                <label
                                    class="dsig-drop"
                                    x-bind:class="{ 'dsig-drop--on': dragging || uploadPreview }"
                                    x-on:dragover.prevent="dragging = true"
                                    x-on:dragleave.prevent="dragging = false"
                                    x-on:drop.prevent="dragging = false; readFile($event.dataTransfer.files?.[0])"
                                    @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
                                >
                                    <template x-if="uploadLoading">
                                        <span class="dsig-drop__hint">Processing…</span>
                                    </template>

                                    <template x-if="! uploadPreview && ! uploadLoading">
                                        <span>
                                            <x-filament::icon icon="heroicon-o-arrow-up-tray" class="dsig-drop__icon" />
                                            <span class="dsig-drop__title">Click or drop an image</span>
                                            <span class="dsig-drop__hint">PNG or JPG — max {{ (int) config('signature.image.max_kb', 512) }} KB</span>
                                        </span>
                                    </template>

                                    <template x-if="uploadPreview && ! uploadLoading">
                                        <span>
                                            <img class="dsig-drop__preview" x-bind:src="uploadPreview" alt="Uploaded signature" />
                                            <span class="dsig-drop__hint">Click to change</span>
                                        </span>
                                    </template>

                                    <input
                                        type="file"
                                        accept="image/png,image/jpeg"
                                        hidden
                                        x-on:change="readFile($event.target.files?.[0]); $event.target.value = ''"
                                    />
                                </label>

                                <p class="dsig-card__warn" x-show="uploadError" x-text="uploadError" x-cloak></p>
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
                            You already have an active signature. Revoke it from
                            <a href="#" x-on:click.prevent="manage(@js($signatures->first()?->uuid))">Manage signatures</a>
                            before registering another.
                        </div>
                    @endif
                @endif
            </div>

            {{--
                Signing devices: this browser, other browsers, and paired
                computers (Kukux Sign Agent). Its own component, mounted once
                the drawer has been opened.
            --}}
            <div x-show="! viewing && mode === 'tabs' && tab === 'devices'" x-cloak>
                @if (! $this->loaded)
                    <div class="dsig-skeleton"></div>
                @else
                    @livewire('kukux-digital-signature.signing-devices', key('dsig-signing-devices'))
                @endif
            </div>

            {{--
                Button placement, per user. Each control moves the button at
                once (it stays visible above the backdrop) and saves shortly
                after; the config placement is what "Reset" returns to.
            --}}
            @if ($settings['customizable'])
                <div x-show="! viewing && mode === 'tabs' && tab === 'settings'" x-cloak>
                    <p class="dsig-section__title">Floating button</p>
                    <p class="dsig-card__meta" style="margin-bottom: .75rem">
                        Choose the corner your Signatures button sits in. It moves as you choose, and only for you.
                    </p>

                    <div class="dsig-corners" role="radiogroup" aria-label="Button corner">
                        @foreach (['top-left' => 'Top left', 'top-right' => 'Top right', 'bottom-left' => 'Bottom left', 'bottom-right' => 'Bottom right'] as $corner => $cornerLabel)
                            <button
                                type="button"
                                role="radio"
                                class="dsig-corner dsig-corner--{{ $corner }}"
                                x-bind:class="pos === '{{ $corner }}' ? 'dsig-corner--on' : ''"
                                x-bind:aria-checked="(pos === '{{ $corner }}').toString()"
                                x-on:click="setCorner('{{ $corner }}')"
                                @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
                            >
                                <span class="dsig-corner__dot"></span>
                                <span class="dsig-corner__label">{{ $cornerLabel }}</span>
                            </button>
                        @endforeach
                    </div>

                    <div class="dsig-form" style="margin-top: 1rem">
                        <label for="dsig-offset-x">
                            Distance from the side: <span x-text="offX + 'px'"></span>
                        </label>
                        <input
                            id="dsig-offset-x"
                            type="range" min="0" max="400" step="4"
                            class="dsig-range"
                            x-model.number="offX"
                            x-on:input="placementChanged()"
                        />

                        <label for="dsig-offset-y">
                            Distance from the <span x-text="pos.startsWith('top') ? 'top' : 'bottom'"></span>:
                            <span x-text="offY + 'px'"></span>
                        </label>
                        <input
                            id="dsig-offset-y"
                            type="range" min="0" max="400" step="4"
                            class="dsig-range"
                            x-model.number="offY"
                            x-on:input="placementChanged()"
                        />

                        <div class="dsig-settings__foot">
                            <button type="button" class="dsig-btn dsig-btn--ghost" x-on:click="resetPlacement()">
                                Reset to default
                            </button>
                            <span class="dsig-card__meta" x-show="saved" x-cloak>Saved</span>
                        </div>
                    </div>
                </div>
            @endif

            {{--
                All documents I've signed: the full record, widened like Manage
                signatures. It replaces the Signed by me page, so nothing here
                navigates away — "The copy I signed" opens in the document pane,
                and closing it comes back to this list.
            --}}
            <div x-show="! viewing && mode === 'signed'" x-cloak>
                @if (! $this->signedLoaded)
                    <div class="dsig-skeleton"></div>
                    <div class="dsig-skeleton"></div>
                @else
                    @php
                        $all     = $this->allSigned;
                        $hasMore = $all->count() > $this->signedLimit;
                        $all     = $all->take($this->signedLimit);
                    @endphp

                    <input
                        type="search"
                        class="dsig-input dsig-signed__search"
                        placeholder="Filter by document title"
                        aria-label="Filter by document title"
                        wire:model.live.debounce.300ms="signedSearch"
                    />

                    @forelse ($all as $request)
                        @php $session = $request->session; @endphp

                        <div class="dsig-card dsig-signed__row" wire:key="dsig-all-signed-{{ $request->id }}">
                            <div class="dsig-signed__body">
                                <p class="dsig-card__title">{{ $this::titleOf($request) }}</p>
                                <p class="dsig-card__meta">
                                    Signed as <strong>{{ $request->role }}</strong>
                                    @if ($request->responded_at)
                                        · {{ $request->responded_at->format('j M Y, g:i a') }}
                                    @endif
                                    @if ($session && ! $session->isComplete())
                                        · {{ $session->isOpen() ? 'awaiting others' : 'routing '.$session->status }}
                                    @endif
                                </p>
                            </div>

                            <div class="dsig-card__actions" style="margin-top: 0">
                                <button
                                    type="button"
                                    class="dsig-btn dsig-btn--ghost"
                                    x-on:click="view({{ $request->id }})"
                                >
                                    The copy I signed
                                </button>

                                @if ($session?->uuid)
                                    <a
                                        class="dsig-btn dsig-btn--ghost"
                                        href="{{ route('signature.documents.show', ['session' => $session->uuid]) }}"
                                        target="_blank"
                                        rel="noopener"
                                    >
                                        Current
                                    </a>
                                @endif
                            </div>
                        </div>
                    @empty
                        <div class="dsig-empty">
                            <x-filament::icon icon="heroicon-o-document-check" />
                            <p>{{ $this->signedSearch === '' ? 'Nothing signed yet' : 'No signed document matches that' }}</p>
                            <p>Documents you sign are kept here.</p>
                        </div>
                    @endforelse

                    @if ($hasMore)
                        <div class="dsig-more">
                            <button type="button" class="dsig-btn dsig-btn--ghost" wire:click="showMoreSigned">
                                Show more
                            </button>
                        </div>
                    @endif
                @endif
            </div>

            {{--
                Manage signatures: the list beside the selected signature's
                details, download, revoke and templates. Replaces the View
                Signature page, so nothing here navigates away except the
                template signer and designer, which are full pages of their own.
            --}}
            <div x-show="! viewing && mode === 'manage'" x-cloak class="dsig-manage-wrap">
                @include('signature::filament.livewire.partials.manage-signatures')
            </div>
        </div>

        {{--
            No link to the full-page inbox. The drawer is the queue now — it
            reads the document, places the signature and keeps the history —
            so a second door to the same room was offering a worse version of
            what the user is already looking at. The page stays routable for
            hosts that want it in their navigation.
        --}}
        <div class="dsig-panel__foot" x-show="! viewing && mode === 'tabs'">
            <button type="button" class="dsig-linkbtn" x-on:click="manage()">Manage signatures</button>
        </div>
    </div>
</div>
