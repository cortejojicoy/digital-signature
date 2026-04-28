<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    @php
        $signatures = $field->getSignatures();
        $statePath  = $getStatePath();
    @endphp

    <div
        x-data="{ selected: '{{ $getState() ?? '' }}' }"
        x-init="$watch('selected', v => { if (v !== null && v !== '') $wire.set('{{ $statePath }}', v); })"
        class="w-full space-y-3"
    >
        @if ($signatures->isEmpty())
            {{-- Empty state --}}
            <div class="flex flex-col items-center justify-center gap-3 rounded-xl border-2 border-dashed
                        border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/[0.03] py-8 px-4 text-center">
                <span class="flex h-10 w-10 items-center justify-center rounded-full
                             bg-gray-100 dark:bg-white/[0.08]">
                    <svg class="h-5 w-5 text-gray-400" viewBox="0 0 20 20" fill="currentColor">
                        <path fill-rule="evenodd" clip-rule="evenodd"
                              d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z"/>
                    </svg>
                </span>
                <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-200">No signatures yet</p>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                        Create a signature from the Signatures page first.
                    </p>
                </div>
            </div>
        @else
            {{-- Signature card grid --}}
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach ($signatures as $sig)
                @php
                    $imgUrl  = $field->getSignatureImageUrl($sig);
                    $sigId   = (string) $sig->id;
                    $method  = ucfirst($sig->source ?? 'draw');
                    $created = $sig->created_at?->format('M j, Y') ?? '';
                @endphp
                <button
                    type="button"
                    x-on:click="selected = '{{ $sigId }}'"
                    x-bind:class="selected === '{{ $sigId }}'
                        ? 'ring-2 ring-primary-500 border-primary-500 bg-primary-50 dark:bg-primary-900/20'
                        : 'border-gray-200 dark:border-white/10 hover:border-primary-300 dark:hover:border-primary-600'"
                    class="relative flex flex-col items-center gap-2 rounded-xl border-2 p-3
                           transition-all duration-150 focus:outline-none focus:ring-2 focus:ring-primary-500"
                >
                    {{-- Signature image --}}
                    @if ($imgUrl)
                    <div class="w-full flex items-center justify-center rounded-lg
                                bg-white dark:bg-gray-900 border border-gray-100 dark:border-white/10
                                h-14 overflow-hidden px-1">
                        <img
                            src="{{ $imgUrl }}"
                            alt="Signature"
                            class="max-h-12 max-w-full object-contain dark:invert dark:brightness-90"
                        />
                    </div>
                    @else
                    <div class="w-full flex items-center justify-center rounded-lg
                                bg-gray-100 dark:bg-white/[0.06] h-14">
                        <svg class="h-6 w-6 text-gray-300" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" clip-rule="evenodd"
                                  d="M4 3a2 2 0 00-2 2v10a2 2 0 002 2h12a2 2 0 002-2V5a2 2 0 00-2-2H4zm12 12H4l4-8 3 6 2-4 3 6z"/>
                        </svg>
                    </div>
                    @endif

                    {{-- Method badge + date --}}
                    <div class="flex w-full items-center justify-between px-0.5">
                        <span class="inline-flex items-center rounded-full px-1.5 py-0.5 text-[10px] font-medium
                            {{ $sig->source === 'draw'
                                ? 'bg-blue-100 text-blue-700 dark:bg-blue-900/30 dark:text-blue-400'
                                : 'bg-purple-100 text-purple-700 dark:bg-purple-900/30 dark:text-purple-400' }}">
                            {{ $method }}
                        </span>
                        <span class="text-[10px] text-gray-400 dark:text-gray-500">{{ $created }}</span>
                    </div>

                    {{-- Selected checkmark --}}
                    <span
                        x-show="selected === '{{ $sigId }}'"
                        x-cloak
                        class="absolute top-1.5 right-1.5 flex h-5 w-5 items-center justify-center
                               rounded-full bg-primary-500"
                    >
                        <svg class="h-3 w-3 text-white" viewBox="0 0 20 20" fill="currentColor">
                            <path fill-rule="evenodd" clip-rule="evenodd"
                                  d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1
                                     0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z"/>
                        </svg>
                    </span>
                </button>
                @endforeach
            </div>

            {{-- Selected hint --}}
            <p
                x-show="!selected"
                x-cloak
                class="text-xs text-gray-400 dark:text-gray-500"
            >
                Click a signature above to select it.
            </p>
        @endif
    </div>
</x-dynamic-component>
