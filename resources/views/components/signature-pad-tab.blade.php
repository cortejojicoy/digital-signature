<div class="rounded-lg bg-gray-50/60 dark:bg-white/[0.03] overflow-hidden">

    {{-- Header hint --}}
    <div class="flex items-center gap-2 px-3 py-2">
        <svg class="h-3.5 w-3.5 text-gray-400 shrink-0" viewBox="0 0 20 20" fill="currentColor">
            <path fill-rule="evenodd" clip-rule="evenodd"
                  d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zm-7-4a1 1 0 11-2 0 1
                     1 0 012 0zM9 9a.75.75 0 000 1.5h.253a.25.25 0 01.244.304
                     l-.459 2.066A1.75 1.75 0 0010.747 15H11a.75.75 0 000-1.5h-.253
                     a.25.25 0 01-.244-.304l.459-2.066A1.75 1.75 0 009.253 9H9z"/>
        </svg>
        <span class="text-xs text-gray-500 dark:text-gray-400">
            Draw your signature, then click <strong class="font-semibold text-gray-700 dark:text-gray-200">{{ $field->getConfirmLabel() }}</strong>
        </span>
    </div>

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
        style="height: {{ $field->getCanvasHeight() + 48 }}px;"
        class="w-full"
    ></div>

    <noscript>
        <p class="p-4 text-sm text-gray-500">JavaScript is required for the signature pad.</p>
    </noscript>
</div>
