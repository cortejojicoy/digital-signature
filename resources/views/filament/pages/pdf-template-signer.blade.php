{{-- PDF Template signer page.

     Hosts the React signing island. The island fetches metadata from
     the signer controller (template info + saved slots + user's
     primary signatures) and renders the SignFlow-style layout: PDF
     preview with slot overlays, bottom strip of stored signatures,
     and a global "Finish & Save" button. --}}
<x-filament-panels::page>
    <div
        data-pdf-signer
        data-template-key="{{ $templateKey }}"
        data-signature-uuid="{{ $signatureUuid }}"
        data-meta-url="{{ route('signature.pdf-templates.signer.meta', ['template' => $templateKey, 'signature' => $signatureUuid]) }}"
        data-page-url-template="{{ route('signature.pdf-templates.page', ['template' => $templateKey, 'page' => '__PAGE__']) }}"
        data-finalize-url="{{ route('signature.pdf-templates.signer.finalize', ['template' => $templateKey, 'signature' => $signatureUuid]) }}"
        data-back-url="{{ request()->headers->get('referer') ?? url()->previous() }}"
        data-csrf-token="{{ csrf_token() }}"
        class="fi-pdf-signer-mount min-h-[70vh]"
    >
        <div class="flex h-[60vh] items-center justify-center text-sm text-gray-500 dark:text-gray-400">
            Loading signer…
        </div>
    </div>
</x-filament-panels::page>
