<x-dynamic-component
    :component="$getFieldWrapperView()"
    :field="$field"
>
    <div
        x-data="signatureField({
            initialTab:    '{{ $field->getShowDrawTab() ? 'draw' : 'upload' }}',
            fieldId:       '{{ $getId() }}',
            showDraw:      {{ $field->getShowDrawTab() ? 'true' : 'false' }},
            showUpload:    {{ $field->getShowUploadTab() ? 'true' : 'false' }},
            fpEndpoint:    '{{ route('signature.device-fingerprint') }}',
        })"
        x-init="
            $watch('value', v => { if (v !== '') $wire.set('{{ $getStatePath() }}', v ?? null); });
        "
        x-on:sig:exported.window="onExported($event.detail)"
        class="w-full"
    >
        {{-- ── Confirmed state ─────────────────────────────────────────────── --}}
        <div
            x-show="isDirty && value"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="flex items-center gap-3 rounded-xl border border-emerald-200/70 dark:border-emerald-800/60
                   bg-emerald-50/70 dark:bg-emerald-950/30 px-4 py-3"
        >
            <span class="shrink-0 flex h-8 w-8 items-center justify-center rounded-full
                         bg-emerald-500 text-white shadow-sm">
                <svg class="h-4 w-4" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" clip-rule="evenodd"
                          d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414
                             0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1
                             1 0 011.414 0z"/>
                </svg>
            </span>

            <img
                x-bind:src="value"
                alt="Signature preview"
                class="h-10 max-w-[160px] object-contain rounded-md
                       bg-white dark:bg-gray-900 border border-emerald-200/70
                       dark:border-emerald-800/60 dark:invert dark:brightness-90 px-1.5"
            />

            <div class="min-w-0 flex-1">
                <p class="text-sm font-semibold text-emerald-800 dark:text-emerald-200 leading-tight">
                    Signature captured
                </p>
                <p class="text-xs text-emerald-600/90 dark:text-emerald-400/80 leading-tight mt-0.5">
                    Ready to use
                </p>
            </div>

            <button
                type="button"
                x-on:click="clear()"
                class="shrink-0 inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5
                       text-xs font-medium text-emerald-700 dark:text-emerald-300
                       bg-white dark:bg-emerald-900/30
                       hover:bg-emerald-100 dark:hover:bg-emerald-900/50
                       border border-emerald-200/70 dark:border-emerald-800/60
                       transition-colors duration-150"
            >
                <svg class="h-3.5 w-3.5" viewBox="0 0 20 20" fill="currentColor">
                    <path fill-rule="evenodd" clip-rule="evenodd"
                          d="M4 2a1 1 0 011 1v2.101a7.002 7.002 0 0111.601 2.566
                             1 1 0 11-1.885.666A5.002 5.002 0 005.999 7H9a1 1
                             0 010 2H4a1 1 0 01-1-1V3a1 1 0 011-1zm.008 9.057a1
                             1 0 011.276.61A5.002 5.002 0 0014.001 13H11a1 1 0
                             110-2h5a1 1 0 011 1v5a1 1 0 11-2 0v-2.101a7.002
                             7.002 0 01-11.601-2.566 1 1 0 01.61-1.276z"/>
                </svg>
                Re-sign
            </button>
        </div>

        {{-- ── Capture container (tabs + panels) ───────────────────────────── --}}
        <div
            x-show="!(isDirty && value)"
            x-cloak
            class="rounded-xl border border-gray-200 dark:border-white/10
                   bg-white dark:bg-white/[0.02] overflow-hidden"
        >
            @if ($field->getShowDrawTab() && $field->getShowUploadTab())
            {{-- Segmented tab control --}}
            <div
                role="tablist"
                aria-label="Signature input method"
                class="flex items-stretch gap-px p-1 bg-gray-50 dark:bg-white/[0.04]
                       border-b border-gray-100 dark:border-white/[0.06]"
            >
                @if ($field->getShowDrawTab())
                <button
                    type="button"
                    role="tab"
                    x-on:click="switchTab('draw')"
                    x-bind:aria-selected="activeTab === 'draw'"
                    x-bind:class="activeTab === 'draw'
                        ? 'bg-white dark:bg-white/[0.08] text-gray-900 dark:text-white shadow-sm ring-1 ring-gray-200/60 dark:ring-white/10'
                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200'"
                    class="flex-1 inline-flex items-center justify-center gap-2 rounded-lg
                           px-3 py-2 text-sm font-medium transition-all duration-150"
                >
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 20 20" fill="currentColor">
                        <path d="M13.586 3.586a2 2 0 112.828 2.828l-.793.793-2.828-2.828.793-.793z"/>
                        <path d="M11.379 5.793L3 14.172V17h2.828l8.38-8.379-2.83-2.828z"/>
                    </svg>
                    Draw
                </button>
                @endif
                @if ($field->getShowUploadTab())
                <button
                    type="button"
                    role="tab"
                    x-on:click="switchTab('upload')"
                    x-bind:aria-selected="activeTab === 'upload'"
                    x-bind:class="activeTab === 'upload'
                        ? 'bg-white dark:bg-white/[0.08] text-gray-900 dark:text-white shadow-sm ring-1 ring-gray-200/60 dark:ring-white/10'
                        : 'text-gray-500 dark:text-gray-400 hover:text-gray-800 dark:hover:text-gray-200'"
                    class="flex-1 inline-flex items-center justify-center gap-2 rounded-lg
                           px-3 py-2 text-sm font-medium transition-all duration-150"
                >
                    <svg class="h-4 w-4 shrink-0" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0
                                 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                    </svg>
                    Upload
                </button>
                @endif
            </div>
            @endif

            {{-- ── Panels ──────────────────────────────────────────────────── --}}
            <div class="p-3 sm:p-4">
                @if ($field->getShowDrawTab())
                <div x-show="activeTab === 'draw'" x-cloak role="tabpanel">
                    @include('signature::components.signature-pad-tab', ['field' => $field])
                </div>
                @endif

                @if ($field->getShowUploadTab())
                <div x-show="activeTab === 'upload'" x-cloak role="tabpanel">
                    @include('signature::components.signature-upload-tab', ['field' => $field])
                </div>
                @endif
            </div>
        </div>

        {{-- ── Hidden inputs (Filament reads these) ───────────────────────── --}}
        <input type="hidden" id="{{ $getId() }}" name="{{ $getName() }}" x-model="value" />
        <input type="hidden" name="source" x-model="source" />
    </div>
</x-dynamic-component>
