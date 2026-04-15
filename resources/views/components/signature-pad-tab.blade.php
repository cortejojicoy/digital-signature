<div
    class="relative rounded-xl border border-gray-200 dark:border-white/10
           bg-white dark:bg-white/5 overflow-hidden"
    style="width: 100%; height: {{ $field->getCanvasHeight() + 48 }}px;"
>
    {{-- React canvas mount point --}}
    <div
        id="sig-canvas-{{ $getId() }}"
        data-signature-canvas
        data-field-id="{{ $getId() }}"
        data-pen-color="{{ $field->getPenColor() }}"
        data-canvas-width="{{ $field->getCanvasWidth() }}"
        data-canvas-height="{{ $field->getCanvasHeight() }}"
        data-min-pen-width="{{ $field->getMinPenWidth() }}"
        data-max-pen-width="{{ $field->getMaxPenWidth() }}"
        data-show-clear="{{ $field->getShowClearBtn() ? 'true' : 'false' }}"
        data-show-undo="{{ $field->getShowUndoBtn() ? 'true' : 'false' }}"
        data-confirm-label="{{ $field->getConfirmLabel() }}"
        class="absolute inset-0"
    ></div>

    {{-- Fallback for JS-disabled / before React hydrates --}}
    <noscript>
        <p class="p-4 text-sm text-gray-500">
            JavaScript is required for the signature pad.
        </p>
    </noscript>
</div>