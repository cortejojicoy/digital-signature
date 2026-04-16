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
        })"
        x-init="
            $watch('value', v => $wire.set('{{ $getStatePath() }}', v || ''));
            getFingerprint().then(fp => {
                window.__sigDeviceFp = fp;
                fetch('{{ route('signature.device-fingerprint') }}', {
                    method:  'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
                    },
                    body: JSON.stringify({ fp }),
                }).catch(() => { /* non-critical — server-side signals still apply */ });
            });
        "
        x-on:sig:exported.window="onExported($event.detail)"
        class="w-full space-y-3"
    >

        {{-- ── Tab bar ──────────────────────────────────────────────────── --}}
        @if ($field->getShowDrawTab() && $field->getShowUploadTab())
        <div class="flex rounded-lg border border-gray-200 dark:border-white/10 overflow-hidden w-fit">
            @if ($field->getShowDrawTab())
            <button
                type="button"
                x-on:click="switchTab('draw')"
                x-bind:class="activeTab === 'draw'
                    ? 'bg-primary-500 text-white'
                    : 'bg-white dark:bg-white/5 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/10'"
                class="px-4 py-2 text-sm font-medium transition-colors duration-150"
            >
                Draw
            </button>
            @endif
            @if ($field->getShowUploadTab())
            <button
                type="button"
                x-on:click="switchTab('upload')"
                x-bind:class="activeTab === 'upload'
                    ? 'bg-primary-500 text-white'
                    : 'bg-white dark:bg-white/5 text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-white/10'"
                class="px-4 py-2 text-sm font-medium transition-colors duration-150"
            >
                Upload
            </button>
            @endif
        </div>
        @endif

        {{-- ── Draw tab (React island) ──────────────────────────────────── --}}
        @if ($field->getShowDrawTab())
        <div x-show="activeTab === 'draw'" x-cloak>
            @include('signature::components.signature-pad-tab', ['field' => $field])
        </div>
        @endif

        {{-- ── Upload tab ──────────────────────────────────────────────── --}}
        @if ($field->getShowUploadTab())
        <div x-show="activeTab === 'upload'" x-cloak>
            @include('signature::components.signature-upload-tab', ['field' => $field])
        </div>
        @endif

        {{-- ── Confirmed preview strip ──────────────────────────────────── --}}
        <div
            x-show="isDirty && value"
            x-cloak
            x-transition:enter="transition ease-out duration-200"
            x-transition:enter-start="opacity-0 translate-y-1"
            x-transition:enter-end="opacity-100 translate-y-0"
            class="flex items-center gap-3 rounded-lg border border-green-200 dark:border-green-800
                   bg-green-50 dark:bg-green-950/30 px-4 py-2"
        >
            <img
                x-bind:src="value"
                alt="Signature preview"
                class="h-10 object-contain rounded"
            />
            <span class="text-xs text-green-700 dark:text-green-400">Signature captured</span>
            <button
                type="button"
                x-on:click="clear()"
                class="ml-auto text-xs text-gray-500 hover:text-red-600 dark:hover:text-red-400
                       transition-colors duration-150"
            >
                Clear
            </button>
        </div>

        {{-- ── Hidden input (Filament reads this) ─────────────────────────  --}}
        <input
            type="hidden"
            id="{{ $getId() }}"
            name="{{ $getName() }}"
            x-model="value"
        />
        <input type="hidden" name="source" x-model="source" />

    </div>
</x-dynamic-component>