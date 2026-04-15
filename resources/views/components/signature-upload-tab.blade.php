<div class="space-y-3">
    <label
        class="flex flex-col items-center justify-center w-full rounded-xl border-2 border-dashed
               border-gray-300 dark:border-white/20 bg-gray-50 dark:bg-white/5
               cursor-pointer hover:border-primary-400 transition-colors duration-150
               py-8 px-4 text-center"
        x-bind:class="uploadPreview ? 'border-primary-400' : ''"
    >
        <template x-if="!uploadPreview">
            <div class="space-y-1">
                <svg class="mx-auto h-8 w-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5"
                          d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021
                             18.75V16.5m-13.5-9L12 3m0 0l4.5 4.5M12 3v13.5"/>
                </svg>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Click to upload PNG or JPG
                </p>
                <p class="text-xs text-gray-400 dark:text-gray-500">
                    Max {{ config('signature.image.max_kb') }}KB
                </p>
            </div>
        </template>

        <template x-if="uploadPreview">
            <img
                x-bind:src="uploadPreview"
                alt="Uploaded signature"
                class="max-h-24 object-contain rounded"
            />
        </template>

        <input
            type="file"
            accept="image/png,image/jpeg"
            class="hidden"
            x-on:change="onFileChange($event)"
        />
    </label>

    {{-- Validation error display --}}
    <p
        x-show="uploadError"
        x-text="uploadError"
        class="text-sm text-red-600 dark:text-red-400"
    ></p>

    {{-- Confirm upload button --}}
    <button
        type="button"
        x-show="uploadPreview && !isDirty"
        x-on:click="confirmUpload()"
        class="w-full rounded-lg bg-primary-600 hover:bg-primary-500 text-white
               text-sm font-medium py-2 px-4 transition-colors duration-150"
    >
        Use this signature
    </button>
</div>