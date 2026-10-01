{{--
    Manage signatures, inside the launcher drawer. Included from
    signature-launcher.blade.php and styled by its .dsig-* rules, for the same
    reason the rest of the drawer is: it renders on every panel page and
    cannot rely on the host's compiled Tailwind.

    Not wire:ignore. A revoke has to re-render the detail pane and the list
    badge, and nothing in here holds state Livewire would destroy.
--}}
@php
    $accent = $settings['color'] ? "--dsig-accent: {$settings['color']}" : null;

    $statusBadge = fn (string $status): string => match ($status) {
        'active', 'signed'  => 'dsig-badge dsig-badge--ok',
        'revoked', 'failed' => 'dsig-badge dsig-badge--bad',
        default             => 'dsig-badge',
    };
@endphp

@if (! $this->manageLoaded)
    <div class="dsig-skeleton"></div>
    <div class="dsig-skeleton"></div>
@else
    @php
        $all      = $this->managedSignatures;
        $selected = $this->managedSignature;
        $statuses = $all->pluck('status')->unique()->values();
    @endphp

    <div class="dsig-manage {{ $selected ? 'dsig-manage--picked' : '' }}">
        {{-- ── List ─────────────────────────────────────────────────── --}}
        <div class="dsig-manage__list">
            @if ($statuses->count() > 1)
                <div class="dsig-chips" role="group" aria-label="Filter by status">
                    <button
                        type="button"
                        class="dsig-filter"
                        x-bind:class="manageFilter === 'all' ? 'dsig-filter--on' : ''"
                        x-on:click="manageFilter = 'all'"
                        @if ($accent) style="{{ $accent }}" @endif
                    >All</button>

                    @foreach (['active', 'pending', 'signed', 'revoked'] as $status)
                        @if ($statuses->contains($status))
                            <button
                                type="button"
                                class="dsig-filter"
                                x-bind:class="manageFilter === @js($status) ? 'dsig-filter--on' : ''"
                                x-on:click="manageFilter = @js($status)"
                                @if ($accent) style="{{ $accent }}" @endif
                            >{{ ucfirst($status) }}</button>
                        @endif
                    @endforeach
                </div>
            @endif

            @forelse ($all as $signature)
                <button
                    type="button"
                    wire:key="dsig-manage-row-{{ $signature->uuid }}"
                    class="dsig-row {{ $selected?->is($signature) ? 'dsig-row--on' : '' }}"
                    x-show="manageFilter === 'all' || manageFilter === @js($signature->status)"
                    wire:click="manage(@js($signature->uuid))"
                    @if ($accent) style="{{ $accent }}" @endif
                >
                    <span class="dsig-row__thumb">
                        @if ($signature->image_path)
                            <img src="{{ $signature->getTemporaryImageUrl() }}" alt="" loading="lazy" />
                        @endif
                    </span>
                    <span class="dsig-row__body">
                        <span class="dsig-row__title">
                            {{ $signature->isPrimary() ? 'Your signature' : $signature->signableTitle() }}
                        </span>
                        <span class="dsig-row__meta">
                            <span class="{{ $statusBadge($signature->status) }}">{{ ucfirst($signature->status) }}</span>
                            · {{ ucfirst($signature->source) }}
                            · {{ ($signature->signed_at ?? $signature->created_at)?->format('M j, Y') }}
                        </span>
                    </span>
                </button>
            @empty
                <div class="dsig-empty">
                    <x-filament::icon icon="heroicon-o-pencil-square" />
                    <p>No signatures yet</p>
                    <p>
                        <a href="#" x-on:click.prevent="leaveManage(); tab = 'library'">Add one from My signatures →</a>
                    </p>
                </div>
            @endforelse
        </div>

        {{-- ── Detail ───────────────────────────────────────────────── --}}
        <div class="dsig-manage__detail">
            @if (! $selected)
                @if ($all->isNotEmpty())
                    <div class="dsig-empty">
                        <x-filament::icon icon="heroicon-o-cursor-arrow-rays" />
                        <p>Pick a signature</p>
                        <p>Its details, downloads and templates appear here.</p>
                    </div>
                @endif
            @else
                @php
                    $isPrimary = $selected->isPrimary();
                    $cards     = $this->templateCardsFor($selected);
                    $uses      = $isPrimary ? $selected->documentUseSummaries() : [];
                    $metadata  = array_filter([
                        'Record ID'               => $selected->uuid,
                        'Image hash (SHA-256)'    => $selected->image_hash,
                        'Device fingerprint'      => $selected->machine_fingerprint,
                        'Device key'              => $selected->device?->shortFingerprint(),
                        'Certificate fingerprint' => $selected->certificate_fingerprint,
                    ], 'filled');
                @endphp

                {{-- Keyed per signature so a half-finished revoke confirmation
                     never carries over to the next one picked. --}}
                <div wire:key="dsig-manage-detail-{{ $selected->uuid }}" x-data="{ confirming: false, copied: null }">
                    <button type="button" class="dsig-linkbtn dsig-manage__back" wire:click="manage()">
                        ← All signatures
                    </button>

                    @if ($selected->image_path)
                        <div class="dsig-detail__image">
                            <img src="{{ $selected->getTemporaryImageUrl() }}" alt="Signature image" />
                        </div>
                    @endif

                    <div class="dsig-detail__actions">
                        @if ($selected->image_path)
                            <a
                                class="dsig-btn dsig-btn--ghost"
                                href="{{ $selected->getTemporaryImageUrl(60) }}"
                                target="_blank"
                                rel="noopener"
                            >Download image</a>
                        @endif

                        @unless ($selected->isRevoked())
                            <button
                                type="button"
                                class="dsig-btn dsig-btn--ghost"
                                x-show="! confirming"
                                x-on:click="confirming = true"
                            >Revoke signature</button>

                            <div class="dsig-confirm" x-show="confirming" x-cloak role="alertdialog" aria-label="Confirm revoke">
                                <span>Revoke permanently? It can no longer be used, and this cannot be undone.</span>
                                <button type="button" class="dsig-btn dsig-btn--ghost" x-on:click="confirming = false">Cancel</button>
                                <button
                                    type="button"
                                    class="dsig-btn dsig-btn--danger"
                                    wire:click="revokeSignature(@js($selected->uuid))"
                                    wire:loading.attr="disabled"
                                    wire:target="revokeSignature"
                                >Revoke</button>
                            </div>
                        @endunless
                    </div>

                    {{-- ── Details ───────────────────────────────────── --}}
                    <div class="dsig-section">
                        <p class="dsig-section__title">Details</p>
                        <dl class="dsig-facts">
                            <div>
                                <dt>Signer</dt>
                                <dd>
                                    {{ $selected->user?->name ?? '—' }}
                                    @if ($selected->user?->email)
                                        <br><span style="opacity: .65">{{ $selected->user->email }}</span>
                                    @endif
                                </dd>
                            </div>
                            <div>
                                <dt>Status</dt>
                                <dd><span class="{{ $statusBadge($selected->status) }}">{{ ucfirst($selected->status) }}</span></dd>
                            </div>
                            <div>
                                <dt>Capture method</dt>
                                <dd><span class="dsig-badge {{ $selected->source === 'draw' ? 'dsig-badge--info' : '' }}">{{ ucfirst($selected->source) }}</span></dd>
                            </div>
                            <div>
                                <dt>{{ $isPrimary ? 'Created on' : 'Signed on' }}</dt>
                                <dd>{{ $selected->deviceSummary() }}</dd>
                            </div>
                            @unless ($isPrimary)
                                <div>
                                    <dt>Document</dt>
                                    <dd>{{ $selected->signableTitle() }}</dd>
                                </div>
                                <div>
                                    <dt>Signed at</dt>
                                    <dd>{{ $selected->signed_at?->format('M j, Y g:i a') ?? 'Not yet signed' }}</dd>
                                </div>
                            @endunless
                            <div>
                                <dt>Registered</dt>
                                <dd>{{ $selected->created_at?->format('M j, Y g:i a') ?? '—' }}</dd>
                            </div>
                            @if ($selected->revoked_at)
                                <div>
                                    <dt>Revoked</dt>
                                    <dd>{{ $selected->revoked_at->format('M j, Y g:i a') }}</dd>
                                </div>
                            @endif
                        </dl>
                    </div>

                    @if ($isPrimary)
                        <div class="dsig-section">
                            <p class="dsig-section__title">Used on</p>
                            @if ($uses === [])
                                <p class="dsig-card__meta">Not used on any document yet.</p>
                            @else
                                <ul class="dsig-uses">
                                    @foreach ($uses as $use)
                                        <li>{{ $use }}</li>
                                    @endforeach
                                </ul>
                            @endif
                        </div>
                    @endif

                    {{-- ── Apply this signature ──────────────────────── --}}
                    @if ($isPrimary)
                        <div class="dsig-section">
                            <p class="dsig-section__title">Apply this signature</p>

                            @if ($cards === [])
                                <div class="dsig-note">
                                    No PDF templates registered yet. Register a <code>PdfTemplate</code>
                                    in your app to expose signable PDFs here.
                                </div>
                            @else
                                <div class="dsig-templates">
                                    @foreach ($cards as $card)
                                        @php $primaryUrl = $card['signerUrl'] ?? $card['designerUrl']; @endphp

                                        <div class="dsig-template" wire:key="dsig-template-{{ $card['key'] }}">
                                            <div class="dsig-template__head">
                                                <p class="dsig-template__label">{{ $card['label'] }}</p>
                                                <p class="dsig-template__key">{{ $card['key'] }}</p>
                                            </div>

                                            <a class="dsig-template__preview" @if ($primaryUrl) href="{{ $primaryUrl }}" @endif>
                                                <img
                                                    src="{{ $card['previewUrl'] }}"
                                                    alt="{{ $card['label'] }} preview"
                                                    loading="lazy"
                                                    onerror="this.style.display='none'"
                                                />
                                            </a>

                                            <div class="dsig-template__foot">
                                                <span class="dsig-badge {{ $card['configured'] ? 'dsig-badge--ok' : '' }}">
                                                    {{ $card['configured'] ? 'Ready' : 'Setup' }}
                                                </span>
                                                <span style="opacity: .65">{{ $card['savedCount'] }}/{{ $card['slotCount'] }} slots</span>
                                            </div>

                                            @if ($card['signerUrl'] || $card['designerUrl'])
                                                <div class="dsig-template__links">
                                                    @if ($card['signerUrl'])
                                                        <a href="{{ $card['signerUrl'] }}">Sign document</a>
                                                    @endif
                                                    @if ($card['designerUrl'])
                                                        <a href="{{ $card['designerUrl'] }}">Open designer</a>
                                                    @endif
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif

                    {{-- ── Security metadata ─────────────────────────── --}}
                    @if ($metadata !== [])
                        <details class="dsig-section dsig-meta">
                            <summary>Security metadata</summary>

                            @foreach ($metadata as $label => $value)
                                <div class="dsig-meta__row">
                                    <span class="dsig-meta__label">{{ $label }}</span>
                                    <span class="dsig-meta__value">{{ $value }}</span>
                                    <button
                                        type="button"
                                        class="dsig-linkbtn"
                                        x-on:click="navigator.clipboard?.writeText(@js($value)); copied = @js($label)"
                                        x-text="copied === @js($label) ? 'Copied' : 'Copy'"
                                    >Copy</button>
                                </div>
                            @endforeach
                        </details>
                    @endif
                </div>
            @endif
        </div>
    </div>
@endif
