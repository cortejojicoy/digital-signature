{{-- View page for a single signature record.

     Layout shape matches the Signature Library mockup: a heading at the
     top describing the action, followed by a card grid of registered
     PdfTemplates this signature can be applied to.

     The page header (set by Filament from ViewRecord::getTitle()) and
     the header actions (Sign Document, Download Image, Revoke) already
     identify the signature and provide all the actions a user needs on
     it — so the previous inline infolist (image / signer / security
     metadata) is intentionally omitted here to keep focus on placement
     and match the mockup's clean library-style layout. --}}
<x-filament-panels::page>
    @php
        /** @var \Kukux\DigitalSignature\Services\PdfTemplateRegistry $registry */
        $registry  = app(\Kukux\DigitalSignature\Services\PdfTemplateRegistry::class);
        $templates = $registry->all();
        $signature = $this->getRecord();

        // Pre-compute per-template stats (configured slot count) so the
        // card markup stays declarative below. Filament dark theme tokens
        // are used directly so the cards look right against both themes.
        $cards = [];
        foreach ($templates as $template) {
            $allSlots = $template->slots();
            $requiredKeys = collect($allSlots)->filter(fn ($s) => $s->required)->pluck('key')->all();
            $declaredKeys = collect($allSlots)->pluck('key')->all();

            $savedKeys = \Kukux\DigitalSignature\Models\PdfTemplateSlot::query()
                ->where('template_key', $template->key())
                ->whereIn('slot_key', $declaredKeys)
                ->pluck('slot_key')
                ->all();

            $allRequiredSaved = empty(array_diff($requiredKeys, $savedKeys));

            try {
                $designerUrl = \Kukux\DigitalSignature\Filament\Pages\PdfTemplateDesigner::getUrl([
                    'templateKey' => $template->key(),
                ]);
            } catch (\Throwable) {
                $designerUrl = null;
            }

            try {
                $signerUrl = \Kukux\DigitalSignature\Filament\Pages\PdfTemplateSigner::getUrl([
                    'templateKey'   => $template->key(),
                    'signatureUuid' => $signature->uuid,
                ]);
            } catch (\Throwable) {
                $signerUrl = null;
            }

            $cards[] = [
                'template'     => $template,
                'designerUrl'  => $designerUrl,
                'signerUrl'    => $signerUrl,
                'previewUrl'   => route('signature.pdf-templates.page', [
                    'template' => $template->key(),
                    'page'     => 1,
                ]),
                'slotCount'    => count($allSlots),
                'savedCount'   => count($savedKeys),
                'configured'   => $allRequiredSaved,
            ];
        }
    @endphp

    {{-- ───── Apply this signature ─────────────────────────────────── --}}
    <x-filament::section>
        <x-slot name="heading">Apply this signature</x-slot>
        <x-slot name="description">
            Pick a PDF template to place this signature on. Click a card to open the signer; use the menu for the placement designer.
        </x-slot>

        @if (empty($cards))
            {{-- Empty state when no PdfTemplates have been registered yet. --}}
            <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50/50 p-8 text-center text-sm text-gray-500 dark:border-white/10 dark:bg-white/5 dark:text-gray-400">
                <div class="mx-auto mb-3 flex h-10 w-10 items-center justify-center rounded-full bg-gray-100 dark:bg-white/10">
                    <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
                </div>
                <div class="font-medium text-gray-700 dark:text-gray-200">
                    No PDF templates registered yet
                </div>
                <p class="mt-1 text-xs">
                    Register a <code class="rounded bg-gray-200 px-1 py-0.5 text-[10px] dark:bg-white/10">PdfTemplate</code> in your app to expose signable PDFs here.
                </p>
            </div>
        @else
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4">
                @foreach ($cards as $card)
                    @php
                        $template    = $card['template'];
                        $designerUrl = $card['designerUrl'];
                        $signerUrl   = $card['signerUrl'];
                        // Card body link prefers the signer flow; falls back
                        // to the designer when the signer URL can't be built
                        // (defensive — same context shouldn't usually happen).
                        $primaryUrl  = $signerUrl ?: $designerUrl;
                    @endphp

                    {{-- The card itself is a wrapper <div>. We avoid making
                         the whole card a single <a> because the 3-dot menu
                         needs its own click target. The big preview area
                         is the link. --}}
                    <div
                        wire:key="template-card-{{ $template->key() }}"
                        class="group relative flex flex-col overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-md dark:border-white/10 dark:bg-gray-900"
                    >
                        {{-- Title bar: uppercase label + 3-dot menu. Compact padding to
                             match the shrunken preview area. --}}
                        <div class="flex items-start justify-between gap-2 border-b border-gray-200 px-3 py-2 dark:border-white/10">
                            <div class="min-w-0">
                                <div class="truncate text-xs font-semibold uppercase tracking-wide text-gray-800 dark:text-gray-200">
                                    {{ $template->label() }}
                                </div>
                                <div class="truncate text-[11px] text-gray-500 dark:text-gray-400">
                                    {{ $template->key() }}
                                </div>
                            </div>
                            <div x-data="{ open: false }" class="relative">
                                <button
                                    type="button"
                                    x-on:click.stop="open = !open"
                                    class="-mr-1 rounded-md p-1 text-gray-400 hover:bg-gray-100 hover:text-gray-600 dark:hover:bg-white/10 dark:hover:text-gray-200"
                                    aria-label="Actions"
                                >
                                    <x-filament::icon icon="heroicon-m-ellipsis-vertical" class="h-4 w-4" />
                                </button>
                                <div
                                    x-show="open"
                                    x-on:click.outside="open = false"
                                    x-transition
                                    class="absolute right-0 z-10 mt-1 w-44 origin-top-right rounded-lg bg-white py-1 shadow-lg ring-1 ring-gray-950/5 dark:bg-gray-800 dark:ring-white/10"
                                    style="display: none;"
                                >
                                    @if ($signerUrl)
                                        <a href="{{ $signerUrl }}" class="block px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5">
                                            Sign document
                                        </a>
                                    @endif
                                    @if ($designerUrl)
                                        <a href="{{ $designerUrl }}" class="block px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-100 dark:text-gray-200 dark:hover:bg-white/5">
                                            Open designer
                                        </a>
                                    @endif
                                </div>
                            </div>
                        </div>

                        {{-- Preview area — fixed compact height (h-24) so the card stays
                             short. The preview is a hint, not the focus. Falls back to
                             an icon when the image fails to load. --}}
                        <a
                            @if ($primaryUrl) href="{{ $primaryUrl }}" @endif
                            class="relative block h-24 w-full overflow-hidden border-b border-gray-200 bg-gradient-to-br from-gray-50 to-gray-100 dark:border-white/10 dark:from-white/5 dark:to-white/10 {{ $primaryUrl ? '' : 'pointer-events-none' }}"
                        >
                            <img
                                src="{{ $card['previewUrl'] }}"
                                alt="{{ $template->label() }} preview"
                                class="h-full w-full object-contain p-1"
                                loading="lazy"
                                onerror="this.style.display='none'; this.nextElementSibling.style.display='flex';"
                            />
                            <div
                                class="absolute inset-0 hidden items-center justify-center text-gray-400"
                                style="display: none;"
                            >
                                <x-filament::icon icon="heroicon-o-document-text" class="h-8 w-8" />
                            </div>
                        </a>

                        {{-- Single-line footer — STATUS pill on the left, slot count on
                             the right. Collapses the previous two-row block to one row. --}}
                        <div class="flex items-center justify-between gap-2 px-3 py-2 text-[11px]">
                            <span class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-medium {{
                                $card['configured']
                                    ? 'bg-emerald-100 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-400'
                                    : 'bg-amber-100 text-amber-700 dark:bg-amber-500/10 dark:text-amber-400'
                            }}">
                                <span class="inline-block h-1.5 w-1.5 rounded-full bg-current"></span>
                                {{ $card['configured'] ? 'Ready' : 'Setup' }}
                            </span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $card['savedCount'] }}/{{ $card['slotCount'] }} slots
                            </span>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
