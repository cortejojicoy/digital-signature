<x-filament-panels::page>

    <div class="max-w-2xl mx-auto space-y-6">

        {{-- Document info card --}}
        @if ($this->signableType && $this->signableId)
        @php
            $signable = $this->signableType::find($this->signableId);
        @endphp
        @if ($signable)
        <x-filament::section>
            <x-slot name="heading">Document</x-slot>
            <p class="text-sm text-gray-600 dark:text-gray-400">
                {{ $signable->getSignableTitle() }}
            </p>
        </x-filament::section>
        @endif
        @endif

        {{-- Form --}}
        <x-filament::section>
            <x-slot name="heading">Sign</x-slot>

            <form wire:submit.prevent="sign" class="space-y-4">
                {{ $this->form }}

                <div class="flex justify-end gap-3 pt-2">
                    <x-filament::button
                        type="submit"
                        size="lg"
                        icon="heroicon-o-pencil-square"
                    >
                        Sign document
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>

        {{-- Signed history for this document --}}
        @if (isset($signable) && $signable?->isSigned())
        <x-filament::section>
            <x-slot name="heading">Signing history</x-slot>
            <ul class="divide-y divide-gray-100 dark:divide-white/10 text-sm">
                @foreach ($signable->signatures()->with('user')->latest()->get() as $sig)
                <li class="py-3 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3">
                        <img
                            src="{{ Storage::disk(config('signature.storage_disk'))->url($sig->image_path) }}"
                            class="h-8 object-contain rounded border border-gray-200 dark:border-white/10"
                            alt="sig"
                        />
                        <div>
                            <p class="font-medium text-gray-900 dark:text-white">
                                {{ $sig->user->name }}
                            </p>
                            <p class="text-xs text-gray-500 dark:text-gray-400">
                                {{ $sig->signed_at?->diffForHumans() ?? 'Pending' }}
                            </p>
                        </div>
                    </div>
                    <span class="capitalize text-xs px-2 py-0.5 rounded-full
                        {{ $sig->status === 'signed' ? 'bg-green-100 text-green-700 dark:bg-green-900/30 dark:text-green-400' : 'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400' }}">
                        {{ $sig->status }}
                    </span>
                </li>
                @endforeach
            </ul>
        </x-filament::section>
        @endif

    </div>

</x-filament-panels::page>