<div
    x-data="signaturePreview({
        imageUrl: '{{ $column->getImageUrl($getRecord()) }}',
        status:   '{{ $column->getStatusBadge($getRecord()) }}',
    })"
    class="flex items-center gap-2"
>
    <template x-if="imageUrl">
        <img
            x-bind:src="imageUrl"
            alt="Signature"
            style="width: {{ $column->getThumbWidth() }}px; height: {{ $column->getThumbHeight() }}px;"
            class="object-contain rounded border border-gray-200 dark:border-white/10
                   bg-white dark:bg-gray-900
                   dark:invert dark:brightness-90"
            x-bind:class="revealed ? 'opacity-100' : 'opacity-0'"
            x-transition:enter="transition ease-out duration-300"
            x-transition:enter-start="opacity-0 scale-95"
            x-transition:enter-end="opacity-100 scale-100"
            x-on:load="revealed = true"
        />
    </template>

    <template x-if="!imageUrl">
        <span class="text-xs text-gray-400 dark:text-gray-600 italic">None</span>
    </template>

    <template x-if="status">
        <span
            x-bind:class="{
                'bg-yellow-100 text-yellow-800 dark:bg-yellow-900/30 dark:text-yellow-400': status === 'pending',
                'bg-green-100 text-green-800 dark:bg-green-900/30 dark:text-green-400':  status === 'signed',
                'bg-red-100 text-red-800 dark:bg-red-900/30 dark:text-red-400':          status === 'revoked',
                'bg-gray-100 text-gray-600 dark:bg-white/10 dark:text-gray-400':         status === 'failed',
            }"
            class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium capitalize"
            x-text="status"
        ></span>
    </template>
</div>