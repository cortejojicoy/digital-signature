{{-- Signature Library — card grid replacement for the default Filament
     ListRecords table. The Filament Page wrapper provides breadcrumbs,
     header, and slot for header actions; everything inside the wrapper
     is the custom card layout.

     The page class extends ListRecords, so $this->getTableRecords()
     still runs the resource's table query (including search, filters,
     and pagination state). We just present the records differently. --}}
<x-filament-panels::page>
    @php
        $records = $this->getTableRecords();
        $userId  = auth()->id();
        $canCreate = $userId
            ? ! \Kukux\DigitalSignature\Models\Signature::primaryActiveFor((int) $userId)->exists()
            : false;
    @endphp

    {{-- Heading + search row.
         Filament's built-in tableSearch Livewire property already powers
         the resource's search, so we just bind a styled input to it. --}}
    <div class="flex flex-col gap-1 sm:flex-row sm:items-end sm:justify-between sm:gap-4">
        <div>
            <h2 class="text-xl font-semibold text-gray-950 dark:text-white">
                Signature Library
            </h2>
            <p class="text-sm text-gray-500 dark:text-gray-400">
                Manage and deploy your authenticated digital signatures.
            </p>
        </div>

        <div class="relative w-full sm:w-72">
            <span class="pointer-events-none absolute inset-y-0 left-3 flex items-center text-gray-400">
                <x-filament::icon icon="heroicon-m-magnifying-glass" class="h-4 w-4" />
            </span>
            <input
                type="search"
                wire:model.live.debounce.300ms="tableSearch"
                placeholder="Search library…"
                class="block w-full rounded-lg border-0 bg-white py-1.5 pl-9 pr-3 text-sm text-gray-900 ring-1 ring-inset ring-gray-300 placeholder:text-gray-400 focus:ring-2 focus:ring-inset focus:ring-primary-600 dark:bg-white/5 dark:text-white dark:ring-white/10 dark:placeholder:text-gray-500"
            />
        </div>
    </div>

    {{-- Card grid.
         4 columns on xl, 3 on lg, 2 on sm, 1 on mobile.
         Each card is a button-styled link to the View page for its record;
         the 3-dot menu lives in the corner and uses stopPropagation so it
         doesn't navigate when interacted with. --}}
    <div class="mt-6 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
        @foreach ($records as $signature)
            @php
                $title = strtoupper(
                    optional($signature->user)->name
                        ?: ('Signature '.\Illuminate\Support\Str::limit($signature->uuid ?? (string) $signature->id, 8, ''))
                );
                $thumbUrl = $signature->getTemporaryImageUrl();
                $statusLabel = match ($signature->status) {
                    'active'  => 'Active',
                    'pending' => 'Pending',
                    'signed'  => 'Signed',
                    'revoked' => 'Archived',
                    'failed'  => 'Failed',
                    default   => ucfirst((string) $signature->status),
                };
                $statusClass = match ($signature->status) {
                    'active', 'signed' => 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400',
                    'revoked'          => 'bg-gray-100 text-gray-600 dark:bg-white/5 dark:text-gray-400',
                    'failed'           => 'bg-red-100 text-red-700 dark:bg-red-500/10 dark:text-red-400',
                    default            => 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400',
                };
                $methodLabel = match ($signature->source) {
                    'upload' => 'Upload',
                    'draw'   => 'Draw',
                    default  => ucfirst((string) $signature->source),
                };
                $viewUrl = static::getResource()::getUrl('view', ['record' => $signature]);
            @endphp

            <div
                wire:key="signature-card-{{ $signature->getKey() }}"
                class="group relative flex flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 transition hover:shadow-md dark:bg-gray-900 dark:ring-white/10"
            >
                {{-- Header: title + 3-dot menu --}}
                <div class="flex items-start justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                    <a
                        href="{{ $viewUrl }}"
                        class="truncate text-xs font-semibold uppercase tracking-wide text-gray-700 hover:text-primary-600 dark:text-gray-200 dark:hover:text-primary-400"
                        title="{{ $title }}"
                    >
                        {{ $title }}
                    </a>

                    {{-- Minimal 3-dot menu (Alpine). Keeps the card click-through
                         intact: the button stops propagation. --}}
                    <div x-data="{ open: false }" class="relative">
                        <button
                            type="button"
                            x-on:click.stop="open = !open"
                            class="-mr-1 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200"
                            aria-label="Actions"
                        >
                            <x-filament::icon icon="heroicon-m-ellipsis-vertical" class="h-4 w-4" />
                        </button>
                        <div
                            x-show="open"
                            x-on:click.outside="open = false"
                            x-transition
                            class="absolute right-0 z-10 mt-1 w-40 origin-top-right rounded-lg bg-white py-1 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10"
                            style="display: none;"
                        >
                            <a
                                href="{{ $viewUrl }}"
                                class="block px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5"
                            >
                                View details
                            </a>
                        </div>
                    </div>
                </div>

                {{-- Body: thumbnail.
                     Using <a> as the wrapper means the whole preview is
                     clickable and respects keyboard navigation. --}}
                <a
                    href="{{ $viewUrl }}"
                    class="block aspect-[3/2] w-full overflow-hidden bg-gray-100 dark:bg-white/5"
                >
                    @if ($thumbUrl)
                        <img
                            src="{{ $thumbUrl }}"
                            alt="{{ $title }}"
                            class="h-full w-full object-contain"
                            loading="lazy"
                        />
                    @else
                        <div class="flex h-full items-center justify-center text-xs text-gray-400">
                            No preview
                        </div>
                    @endif
                </a>

                {{-- Footer: status + method --}}
                <div class="space-y-3 px-4 py-3 text-xs">
                    <div>
                        <div class="font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Status
                        </div>
                        <span class="mt-1 inline-flex items-center gap-1 rounded-md px-2 py-0.5 font-medium {{ $statusClass }}">
                            @if (in_array($signature->status, ['active', 'signed'], true))
                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-current"></span>
                            @endif
                            {{ $statusLabel }}
                        </span>
                    </div>
                    <div>
                        <div class="font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">
                            Method
                        </div>
                        <span class="mt-1 inline-flex rounded-md bg-gray-100 px-2 py-0.5 font-medium uppercase tracking-wide text-gray-700 dark:bg-white/5 dark:text-gray-300">
                            {{ $methodLabel }}
                        </span>
                    </div>
                </div>
            </div>
        @endforeach

        {{-- Create-new tile.
             Mirrors the visibility rule from the createSignature header
             action so we don't show "+ Create New" when the user already
             has an active primary signature. --}}
        @if ($canCreate)
            <button
                type="button"
                wire:click="mountAction('createSignature')"
                class="flex min-h-[16rem] items-center justify-center rounded-xl border-2 border-dashed border-gray-300 bg-white/40 text-gray-500 transition hover:border-primary-500 hover:text-primary-600 dark:border-white/10 dark:bg-white/5 dark:text-gray-400 dark:hover:border-primary-400 dark:hover:text-primary-400"
            >
                <span class="flex flex-col items-center gap-2 text-sm font-medium">
                    <span class="flex h-12 w-12 items-center justify-center rounded-full bg-gray-100 dark:bg-white/10">
                        <x-filament::icon icon="heroicon-o-plus" class="h-6 w-6" />
                    </span>
                    Create new
                </span>
            </button>
        @endif
    </div>

    {{-- Empty state — only when there are truly no records AND we can't
         create one (the create tile already handles the "no records yet"
         case for users who can create). --}}
    @if ($records->isEmpty() && ! $canCreate)
        <div class="mt-8 rounded-xl border border-dashed border-gray-300 bg-white/40 p-12 text-center text-sm text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
            No signatures match your search.
        </div>
    @endif

    {{-- Pagination — uses Filament's paginator styling. Only renders when
         there's more than one page so empty / small lists stay tidy. --}}
    @if ($records->hasPages())
        <div class="mt-6">
            {{ $records->links() }}
        </div>
    @endif
</x-filament-panels::page>
