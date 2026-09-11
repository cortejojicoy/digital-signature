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
--}}
@php
    $settings = $this->settings;
    $count    = $this->count;
    $onLeft   = str_ends_with($settings['position'], '-left');
@endphp

<div
    class="dsig-launcher dsig-launcher--{{ $settings['position'] }}"
    x-data="{
        open: false,
        loaded: @js($this->loaded),
        toggle() {
            this.open = ! this.open

            if (this.open && ! this.loaded) {
                this.loaded = true
                $wire.loadRequests()
            }
        },
    }"
    x-on:keydown.escape.window="open = false"
    @if ($settings['poll'] > 0) wire:poll.{{ $settings['poll'] }}s.visible @endif
>
    <style>
        .dsig-launcher { position: fixed; z-index: 40; }
        .dsig-launcher--bottom-right { right: 1.5rem; bottom: 1.5rem; }
        .dsig-launcher--bottom-left  { left: 1.5rem;  bottom: 1.5rem; }
        .dsig-launcher--top-right    { right: 1.5rem; top: 5.5rem; }
        .dsig-launcher--top-left     { left: 1.5rem;  top: 5.5rem; }

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
            position: fixed; inset: 0; z-index: 39;
            background: rgb(0 0 0 / 0.3); backdrop-filter: blur(1px);
        }

        .dsig-panel {
            position: fixed; top: 0; bottom: 0; z-index: 41;
            display: flex; flex-direction: column;
            width: min(26rem, 100vw);
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

        .dsig-panel__body { flex: 1; overflow-y: auto; padding: 1rem 1.25rem; }
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

        @media (max-width: 640px) {
            .dsig-panel { width: 100vw; }
        }
    </style>

    @if (! ($settings['hideWhenEmpty'] && $count === 0))
        <button
            type="button"
            class="dsig-fab"
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

        <div class="dsig-panel__body">
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
                        @if ($this->registerUrl)
                            <a href="{{ $this->registerUrl }}">Register one now →</a>
                        @endif
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
                            <button
                                type="button"
                                class="dsig-btn"
                                @if ($settings['color']) style="--dsig-accent: {{ $settings['color'] }}" @endif
                                wire:click="signRequest({{ $request->id }})"
                                wire:loading.attr="disabled"
                                wire:target="signRequest({{ $request->id }})"
                                @disabled($blocked || $signatures->isEmpty())
                            >
                                <span wire:loading.remove wire:target="signRequest({{ $request->id }})">Sign</span>
                                <span wire:loading wire:target="signRequest({{ $request->id }})">Signing…</span>
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

        @if ($this->inboxUrl || $this->libraryUrl)
            <div class="dsig-panel__foot">
                @if ($this->inboxUrl)
                    <a href="{{ $this->inboxUrl }}">Open full inbox</a>
                @endif

                @if ($this->libraryUrl)
                    <a href="{{ $this->libraryUrl }}">My signatures</a>
                @endif
            </div>
        @endif
    </div>
</div>
