{{--
    SignatoryPanel — "who signs this, and where are they up to?"

    Everything rendered here comes from SignatoryRouter via the entry, so this
    view never re-derives state; it only presents it. Colours come from
    RouteState::color() so the panel, badges and any host UI agree.
--}}
@php
    $routes  = $getRoutes();
    $session = $getSession();
    $error   = $getRoutingError();
    $showPlacement = $shouldShowPlacement();
@endphp

<div class="fi-sig-panel space-y-3">
    @if ($error)
        <div class="rounded-lg border border-danger-300 bg-danger-50 p-4 text-sm text-danger-700
                    dark:border-danger-800 dark:bg-danger-950/40 dark:text-danger-300">
            <p class="font-medium">Signatory routing failed</p>
            <p class="mt-1">{{ $error }}</p>
        </div>
    @elseif (empty($routes))
        <p class="text-sm text-gray-500 dark:text-gray-400">
            This template declares no signature slots.
        </p>
    @else
        @if ($session)
            <div class="flex items-center justify-between gap-3 text-xs text-gray-500 dark:text-gray-400">
                <span>
                    Session {{ \Illuminate\Support\Str::limit($session->uuid, 8, '') }} —
                    <span @class([
                        'font-medium',
                        'text-success-600 dark:text-success-400' => $session->isComplete(),
                        'text-warning-600 dark:text-warning-400' => $session->isOpen(),
                    ])>{{ $session->status }}</span>
                    ({{ $session->sequence_mode }})
                </span>

                @if ($session->completed_at)
                    <span>Completed {{ $session->completed_at->diffForHumans() }}</span>
                @endif
            </div>
        @endif

        <ul role="list" class="divide-y divide-gray-200 overflow-hidden rounded-xl border border-gray-200
                               bg-white dark:divide-white/10 dark:border-white/10 dark:bg-white/5">
            @foreach ($routes as $route)
                @php
                    $state = $route->state;
                    $blocker = $route->blockerMessage();
                @endphp

                <li class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3">
                    <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full
                                 bg-gray-100 text-xs font-semibold text-gray-600
                                 dark:bg-white/10 dark:text-gray-300">
                        {{ $loop->iteration }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex items-center gap-2">
                            <span class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                {{ $route->label() }}
                            </span>

                            @if ($route->isRequired())
                                <span class="text-xs text-danger-500" title="Required">*</span>
                            @endif
                        </div>

                        <p class="truncate text-sm text-gray-500 dark:text-gray-400">
                            {{ $route->signerName() ?? 'Unassigned' }}
                            @if ($route->signerEmail())
                                <span class="text-gray-400 dark:text-gray-500">· {{ $route->signerEmail() }}</span>
                            @endif
                        </p>

                        @if ($blocker)
                            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">{{ $blocker }}</p>
                        @endif

                        @if ($showPlacement && $route->position)
                            <p class="mt-0.5 font-mono text-xs text-gray-400 dark:text-gray-500">
                                p{{ $route->position['page'] }}
                                · x{{ round($route->position['x']) }}
                                · y{{ round($route->position['y']) }}
                                · {{ round($route->position['width']) }}×{{ round($route->position['height']) }}
                            </p>
                        @endif
                    </div>

                    @if ($route->signature && $route->state->value === 'signed')
                        <img
                            src="{{ $route->signature->getTemporaryImageUrl(10) }}"
                            alt="{{ $route->signerName() }} signature"
                            class="h-8 max-w-[8rem] object-contain"
                        />
                    @endif

                    <span @class([
                        'shrink-0 rounded-md px-2 py-1 text-xs font-medium ring-1 ring-inset',
                        'bg-success-50 text-success-700 ring-success-600/20 dark:bg-success-400/10 dark:text-success-400 dark:ring-success-400/30' => $state->color() === 'success',
                        'bg-warning-50 text-warning-700 ring-warning-600/20 dark:bg-warning-400/10 dark:text-warning-400 dark:ring-warning-400/30' => $state->color() === 'warning',
                        'bg-danger-50 text-danger-700 ring-danger-600/20 dark:bg-danger-400/10 dark:text-danger-400 dark:ring-danger-400/30' => $state->color() === 'danger',
                        'bg-info-50 text-info-700 ring-info-600/20 dark:bg-info-400/10 dark:text-info-400 dark:ring-info-400/30' => $state->color() === 'info',
                        'bg-gray-50 text-gray-600 ring-gray-500/20 dark:bg-white/5 dark:text-gray-400 dark:ring-white/20' => $state->color() === 'gray',
                    ])>
                        {{ $state->label() }}
                    </span>

                    @if ($route->request?->responded_at)
                        <span class="shrink-0 text-xs text-gray-400 dark:text-gray-500">
                            {{ $route->request->responded_at->format('Y-m-d H:i') }}
                        </span>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif
</div>
