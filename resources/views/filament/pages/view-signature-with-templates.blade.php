{{-- View page for a single signature record.

     Layout: the default Filament ViewRecord infolist on top (signature
     image, signer details, security metadata), followed by a card grid
     of registered PdfTemplates this signature can be applied to.

     Each card links to the placement designer for that template. Once
     the signer flow (step 5) lands, the card link target switches to
     the signer page — the card markup stays the same. --}}
<x-filament-panels::page>
    {{-- ───── Default infolist section (image / signer / metadata) ───── --}}
    {{ $this->infolist }}

    @php
        /** @var \Kukux\DigitalSignature\Services\PdfTemplateRegistry $registry */
        $registry  = app(\Kukux\DigitalSignature\Services\PdfTemplateRegistry::class);
        $templates = $registry->all();
        $signature = $this->getRecord();
    @endphp

    {{-- ───── PDFs this signature can be applied to ────────────────── --}}
    <x-filament::section
        :heading="'Apply this signature'"
        :description="'PDFs you can place this signature on. Each card opens the placement designer for the chosen template.'"
        class="mt-6"
    >
        @if (empty($templates))
            {{-- Empty state when no PdfTemplates have been registered yet.
                 Points users at the docs so they know how to register one. --}}
            <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50/50 p-8 text-center text-sm text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 dark:bg-white/10">
                    <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                </div>
                <div class="font-medium text-gray-700 dark:text-gray-200">
                    No PDF templates registered yet
                </div>
                <p class="mt-1 text-xs">
                    Register a <code class="rounded bg-gray-200 px-1 py-0.5 text-[10px] dark:bg-white/10">PdfTemplate</code> in your app to expose signable PDFs here.
                    See <code class="text-[10px]">docs/pdf-templates.md</code> for the contract.
                </p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($templates as $template)
                    @php
                        // Designer URL for this template. Wrapped in try/catch
                        // because Filament throws when the current request
                        // isn't tied to a panel (rare here but defensive).
                        try {
                            $designerUrl = \Kukux\DigitalSignature\Filament\Pages\PdfTemplateDesigner::getUrl([
                                'templateKey' => $template->key(),
                            ]);
                        } catch (\Throwable) {
                            $designerUrl = null;
                        }

                        $slotCount = count($template->slots());
                    @endphp

                    <a
                        @if ($designerUrl) href="{{ $designerUrl }}" @endif
                        wire:key="template-card-{{ $template->key() }}"
                        class="group flex flex-col overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 transition hover:-translate-y-0.5 hover:shadow-md hover:ring-primary-500 dark:bg-gray-900 dark:ring-white/10 dark:hover:ring-primary-400 {{ $designerUrl ? '' : 'pointer-events-none opacity-60' }}"
                    >
                        {{-- Header --}}
                        <div class="flex items-start justify-between gap-2 border-b border-gray-200 px-4 py-3 dark:border-white/10">
                            <div class="min-w-0">
                                <div class="truncate text-xs font-semibold uppercase tracking-wide text-gray-700 dark:text-gray-200">
                                    {{ $template->label() }}
                                </div>
                                <div class="truncate text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ $template->key() }}
                                </div>
                            </div>
                            <span class="rounded-md bg-primary-50 px-2 py-0.5 text-[10px] font-medium text-primary-700 dark:bg-primary-500/10 dark:text-primary-400">
                                {{ $slotCount }} {{ \Illuminate\Support\Str::plural('slot', $slotCount) }}
                            </span>
                        </div>

                        {{-- Visual: signature preview, faintly tinted, as a
                             quick reminder which signature is being placed --}}
                        <div class="relative flex h-32 items-center justify-center bg-gradient-to-br from-gray-50 to-gray-100 dark:from-white/5 dark:to-white/10">
                            @if ($signature->image_path && ($preview = $signature->getTemporaryImageUrl()))
                                <img
                                    src="{{ $preview }}"
                                    alt="Signature preview"
                                    class="max-h-20 w-auto object-contain opacity-80 transition group-hover:opacity-100"
                                    loading="lazy"
                                />
                            @else
                                <x-filament::icon icon="heroicon-o-document-text" class="h-10 w-10 text-gray-400" />
                            @endif
                        </div>

                        {{-- Footer --}}
                        <div class="flex items-center justify-between gap-2 px-4 py-3 text-xs">
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $designerUrl ? 'Open placement designer' : 'Designer unavailable' }}
                            </span>
                            <span class="inline-flex items-center gap-1 font-medium text-primary-600 group-hover:text-primary-700 dark:text-primary-400 dark:group-hover:text-primary-300">
                                Apply
                                <x-filament::icon icon="heroicon-m-arrow-up-right" class="h-3.5 w-3.5" />
                            </span>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
