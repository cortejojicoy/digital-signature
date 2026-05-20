{{-- PDF Template placement designer. The page Layout follows Filament's
     own Page conventions; all interactivity lives inside the React island
     mounted on the [data-pdf-designer] element below. --}}
<x-filament-panels::page>
    <div
        data-pdf-designer
        data-template-key="{{ $templateKey }}"
        data-meta-url="{{ route('signature.pdf-templates.meta', ['template' => $templateKey]) }}"
        data-page-url-template="{{ route('signature.pdf-templates.page', ['template' => $templateKey, 'page' => '__PAGE__']) }}"
        data-save-url-template="{{ route('signature.pdf-templates.slot.save', ['template' => $templateKey, 'slot' => '__SLOT__']) }}"
        data-csrf-token="{{ csrf_token() }}"
        class="fi-pdf-designer-mount min-h-[60vh]"
    >
        {{-- Skeleton shown until the React island mounts. --}}
        <div class="flex h-[60vh] items-center justify-center text-sm text-gray-500 dark:text-gray-400">
            Loading designer…
        </div>
    </div>
</x-filament-panels::page>
