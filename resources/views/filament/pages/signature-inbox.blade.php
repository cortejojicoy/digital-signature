{{--
    Signature inbox — the documents waiting on the authenticated user.

    Each "Sign" click produces the PKCS#7 signature inside this user's own
    request with their own certificate, which is what keeps the default
    consent model honest: the automation finds the document for them, it does
    not sign on their behalf.
--}}
<x-filament-panels::page>
    @php
        $requests = $this->requests;
    @endphp

    @if ($requests->isEmpty())
        <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed
                    border-gray-300 px-6 py-16 text-center dark:border-white/20">
            <x-filament::icon
                icon="heroicon-o-check-circle"
                class="h-10 w-10 text-gray-400 dark:text-gray-500"
            />
            <p class="text-sm font-medium text-gray-950 dark:text-white">Nothing waiting on you</p>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Documents needing your signature will appear here.
            </p>
        </div>
    @else
        <ul role="list" class="space-y-3">
            @foreach ($requests as $request)
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

                <li class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                {{ $title }}
                            </p>
                            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                You are listed as <span class="font-medium">{{ $request->role }}</span>
                                @if ($session?->isSequential())
                                    · step {{ $request->sequence }}
                                @endif
                            </p>
                            @if ($request->requested_at)
                                <p class="mt-0.5 text-xs text-gray-400 dark:text-gray-500">
                                    Requested {{ $request->requested_at->diffForHumans() }}
                                </p>
                            @endif

                            @if ($blocked)
                                <p class="mt-2 text-xs text-warning-600 dark:text-warning-400">
                                    An earlier signatory must sign before you can.
                                </p>
                            @endif
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <x-filament::button
                                wire:click="signRequest({{ $request->id }})"
                                wire:loading.attr="disabled"
                                wire:target="signRequest({{ $request->id }})"
                                :disabled="$blocked"
                                icon="heroicon-m-pencil-square"
                                size="sm"
                            >
                                Sign
                            </x-filament::button>

                            <x-filament::button
                                wire:click="declineRequest({{ $request->id }})"
                                wire:confirm="Decline to sign this document?"
                                color="gray"
                                size="sm"
                            >
                                Decline
                            </x-filament::button>
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</x-filament-panels::page>
