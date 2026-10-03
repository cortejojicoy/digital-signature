{{--
    Documents I've signed: each signature this user put on a routed document,
    with the copy they signed (the version their signature produced) and the
    document as it stands now. Read-only; nothing here can sign or unsign.
--}}
<x-filament-panels::page>
    @php
        $requests = $this->signedRequests;
        $more = $requests->count() > $this->limit;
        $requests = $requests->take($this->limit);
    @endphp

    <div style="max-width:24rem;">
        <x-filament::input.wrapper>
            <x-filament::input
                type="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Filter by document title"
            />
        </x-filament::input.wrapper>
    </div>

    @if ($requests->isEmpty())
        <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-dashed
                    border-gray-300 px-6 py-16 text-center dark:border-white/20">
            <x-filament::icon icon="heroicon-o-document-check" class="h-10 w-10 text-gray-400 dark:text-gray-500" />
            <p class="text-sm font-medium text-gray-950 dark:text-white">
                {{ $this->search === '' ? 'Nothing signed yet' : 'No signed document matches that' }}
            </p>
            <p class="text-sm text-gray-500 dark:text-gray-400">Documents you sign are kept here.</p>
        </div>
    @else
        <ul role="list" class="space-y-3">
            @foreach ($requests as $request)
                @php
                    $session = $request->session;
                    $mine = $request->signature?->signed_document_path
                        ? route('signature.request.document', ['signatureRequest' => $request->id])
                        : null;
                    $current = $session?->uuid
                        ? route('signature.documents.show', ['session' => $session->uuid])
                        : null;
                @endphp

                <li class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-white/5">
                    <div class="flex flex-wrap items-start justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="truncate text-sm font-medium text-gray-950 dark:text-white">
                                {{ $this::titleOf($request) }}
                            </p>
                            <p class="mt-0.5 text-sm text-gray-500 dark:text-gray-400">
                                Signed as <span class="font-medium">{{ $request->role }}</span>
                                @if ($request->responded_at)
                                    · {{ $request->responded_at->format('j M Y, g:i a') }}
                                @endif
                                @if ($session && ! $session->isComplete())
                                    · {{ $session->isOpen() ? 'awaiting others' : 'routing '.$session->status }}
                                @endif
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            @if ($mine)
                                <x-filament::button tag="a" :href="$mine" target="_blank" size="sm" icon="heroicon-m-document-check">
                                    The copy I signed
                                </x-filament::button>
                            @endif
                            @if ($current)
                                <x-filament::button tag="a" :href="$current" target="_blank" size="sm" color="gray">
                                    Current
                                </x-filament::button>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>

        @if ($more)
            <div>
                <x-filament::button color="gray" wire:click="showMore">Show more</x-filament::button>
            </div>
        @endif
    @endif
</x-filament-panels::page>
