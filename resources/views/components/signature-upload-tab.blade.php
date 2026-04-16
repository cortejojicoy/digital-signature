<div class="space-y-3">

    {{-- Drop zone --}}
    <label
        class="group relative flex flex-col items-center justify-center w-full rounded-xl
               border-2 border-dashed border-gray-200 dark:border-white/10
               bg-gray-50 dark:bg-white/[0.03] cursor-pointer
               hover:border-primary-400 dark:hover:border-primary-500
               hover:bg-primary-50/40 dark:hover:bg-primary-900/10
               transition-all duration-150"
        x-bind:class="uploadPreview
            ? 'border-primary-400 dark:border-primary-500 bg-primary-50/40 dark:bg-primary-900/10 py-4'
            : 'py-8'"
    >
        {{-- Loading state --}}
        <template x-if="uploadLoading">
            <div class="flex flex-col items-center gap-2 px-4">
                <svg class="h-6 w-6 animate-spin text-primary-500" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor"
                          d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <p class="text-xs text-gray-400">Processing…</p>
            </div>
        </template>

        {{-- Empty state --}}
        <template x-if="!uploadPreview && !uploadLoading">
            <div class="flex flex-col items-center gap-2 px-4 text-center">
                <span class="flex h-10 w-10 items-center justify-center rounded-full
                             bg-gray-100 dark:bg-white/10
                             group-hover:bg-primary-100 dark:group-hover:bg-primary-900/30
                             transition-colors duration-150">
                    <svg class="h-5 w-5 text-gray-400 group-hover:text-primary-500 transition-colors duration-150"
                         fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                        <path stroke-linecap="round" stroke-linejoin="round"
                              d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0
                                 0021 18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                    </svg>
                </span>
                <div>
                    <p class="text-sm font-medium text-gray-700 dark:text-gray-300">
                        Click to upload
                    </p>
                    <p class="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                        PNG or JPG &mdash; max {{ config('signature.image.max_kb') }}KB
                    </p>
                </div>
            </div>
        </template>

        {{-- Preview state (auto-confirmed) --}}
        <template x-if="uploadPreview && !uploadLoading">
            <div class="flex flex-col items-center gap-2 px-4">
                <img
                    x-bind:src="uploadPreview"
                    alt="Uploaded signature"
                    class="max-h-24 max-w-full object-contain rounded-lg
                           border border-primary-200 dark:border-primary-700"
                />
                <p class="text-xs text-primary-600 dark:text-primary-400 font-medium">
                    Click to change
                </p>
            </div>
        </template>

        <input
            type="file"
            accept="image/png,image/jpeg"
            class="hidden"
            x-on:change="onFileChange($event)"
        />
    </label>

    {{-- Error --}}
    <div
        x-show="uploadError"
        x-cloak
        class="flex items-center gap-2 rounded-lg bg-red-50 dark:bg-red-950/40
               border border-red-200 dark:border-red-800 px-3 py-2"
    >
        <svg class="h-4 w-4 shrink-0 text-red-500" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" clip-rule="evenodd"
                  d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-8-5a.75.75 0 01.75.75v4.5a.75.75
                     0 01-1.5 0v-4.5A.75.75 0 0110 5zm0 10a1 1 0 100-2 1 1 0 000 2z"/>
        </svg>
        <p x-text="uploadError" class="text-sm text-red-600 dark:text-red-400"></p>
    </div>

</div>
