{{--
    The person panel's home — see Kukux\DigitalSignature\Hub\Filament\Pages\Profile.
    No topbar or sidebar on this panel: the header below is the user menu.
--}}
<x-filament-panels::page>
    @include('signature::hub.partials.styles')

    @php
        $status = $identity?->status ?? \Kukux\DigitalSignature\Models\Identity::UNIDENTIFIED;
        $badge = match ($status) {
            'verified'             => ['Verified', 'dsh-badge--ok'],
            'pending_verification' => ['Waiting for verification', 'dsh-badge--warn'],
            'separated', 'retired', 'rejected' => [ucfirst($status), 'dsh-badge--bad'],
            default                => ['Not identified', ''],
        };
    @endphp

    <div class="dsh dsh-stack" style="max-width: 56rem; margin: 0 auto; width: 100%;">
        {{-- ── Header: who, and what the user menu would offer ─────────── --}}
        <div class="dsh-header">
            <div>
                <h2 style="font-size: 1.25rem;">{{ $person?->name ?? $user?->name }}</h2>
                <p class="dsh-meta">
                    {{ collect([$person?->position, $person?->unit])->filter()->join(' · ') }}
                    <span class="dsh-badge {{ $badge[1] }}" style="margin-left: .35rem;">{{ $badge[0] }}</span>
                </p>
            </div>
            <div class="dsh-row" style="margin-top: 0;">
                @if ($adminUrl)
                    <a class="dsh-btn" href="{{ $adminUrl }}">Open admin panel</a>
                @endif
                <form method="POST" action="{{ filament()->getLogoutUrl() }}">
                    @csrf
                    <button type="submit" class="dsh-btn">Sign out</button>
                </form>
            </div>
        </div>

        @if ($status === 'pending_verification')
            <div class="dsh-note dsh-note--warn">
                A signature admin still has to confirm you are {{ $person?->name ?? 'who you said' }}. Until then you can
                draw your signature, but you can't sign documents or sign in to other apps with it.
            </div>
        @endif

        @foreach ($outgoing as $transfer)
            <div class="dsh-note dsh-note--warn" wire:key="dsh-out-{{ $transfer->id }}">
                <strong>A new computer wants to take over your signature.</strong>
                {{ data_get($transfer->agentJob?->meta, 'transfer.device', 'A new computer') }} asked to become your signing
                computer. Approve only if that was you: this computer will be released.
                <div class="dsh-row">
                    @if ($transfer->agentJob && $transfer->agentJob->status === 'pending')
                        <button type="button" class="dsh-btn dsh-btn--primary" wire:click="approveTransfer({{ $transfer->id }})">Approve on this computer</button>
                    @endif
                    <button type="button" class="dsh-btn dsh-btn--danger" wire:click="refuseTransfer({{ $transfer->id }})"
                            wire:confirm="Refuse moving your signature to the new computer?">That wasn't me</button>
                </div>
            </div>
        @endforeach

        <div class="dsh-grid dsh-grid--2">
            {{-- ── Personnel card ─────────────────────────────────────── --}}
            <div class="dsh-card">
                <h3>Personnel record</h3>
                @if ($person)
                    <dl class="dsh-dl">
                        <dt>Name</dt><dd>{{ $person->name }}</dd>
                        <dt>Employee no.</dt><dd class="dsh-mono">{{ $person->empNo ?? '—' }}</dd>
                        <dt>Unit</dt><dd>{{ $person->unit ?? '—' }}</dd>
                        <dt>Position</dt><dd>{{ $person->position ?? '—' }}</dd>
                        <dt>Email</dt><dd>{{ $person->email ?? '—' }}</dd>
                        <dt>Status</dt><dd>{{ $person->active ? 'Active' : 'Inactive' }}</dd>
                    </dl>
                @else
                    <p class="dsh-meta">Not linked to a personnel record.</p>
                @endif
            </div>

            {{-- ── Signature and certificate ──────────────────────────── --}}
            <div class="dsh-card">
                <h3>Signature</h3>
                @if ($signature)
                    <img class="dsh-sigimg" src="{{ $signature->getTemporaryImageUrl() }}" alt="Your signature" />
                    <dl class="dsh-dl">
                        <dt>SHA-256</dt><dd class="dsh-mono">{{ \Illuminate\Support\Str::limit($signature->image_hash, 24, '…') }}</dd>
                        <dt>Added</dt><dd>{{ $signature->created_at?->format('M j, Y') }}</dd>
                        <dt>Usable</dt><dd>{{ $status === 'verified' ? 'Yes' : 'After verification' }}</dd>
                        @if ($certificate)
                            <dt>Certificate</dt><dd class="dsh-mono">{{ \Illuminate\Support\Str::limit($certificate->fingerprint, 24, '…') }}</dd>
                            <dt>Valid until</dt><dd>{{ $certificate->expires_at?->format('M j, Y') }}</dd>
                        @endif
                    </dl>
                @elseif ($canRegister)
                    <div
                        x-data="{
                            method: 'draw',
                            uploadError: null,
                            maxKb: @js((int) config('signature.image.max_kb', 512)),
                            init() {
                                this.onPad = (e) => { if (e.detail?.fieldId === 'dsh-profile-pad') this.$wire.set('newSignature', e.detail.png) }
                                window.addEventListener('sig:exported', this.onPad)
                            },
                            destroy() { window.removeEventListener('sig:exported', this.onPad) },
                            readFile(file) {
                                if (! file) return
                                this.uploadError = null
                                if (! ['image/png', 'image/jpeg'].includes(file.type)) { this.uploadError = 'Only PNG and JPG files are allowed.'; return }
                                if (file.size > this.maxKb * 1024) { this.uploadError = `File must be smaller than ${this.maxKb} KB.`; return }
                                const reader = new FileReader()
                                reader.onload = (e) => {
                                    const img = new Image()
                                    img.onload = () => {
                                        const canvas = document.createElement('canvas')
                                        canvas.width = img.naturalWidth || 600
                                        canvas.height = img.naturalHeight || 200
                                        canvas.getContext('2d').drawImage(img, 0, 0)
                                        this.$wire.set('newSignature', canvas.toDataURL('image/png'))
                                    }
                                    img.src = e.target.result
                                }
                                reader.readAsDataURL(file)
                            },
                        }"
                    >
                        <p class="dsh-meta">Draw or upload your signature. It is what apps stamp on documents you sign.</p>
                        <div class="dsh-row">
                            <button type="button" class="dsh-btn" x-bind:class="method === 'draw' && 'dsh-btn--primary'" x-on:click="method = 'draw'">Draw</button>
                            <button type="button" class="dsh-btn" x-bind:class="method === 'upload' && 'dsh-btn--primary'" x-on:click="method = 'upload'">Upload</button>
                        </div>

                        {{-- The same React pad the SignaturePad field and the launcher mount. --}}
                        <div wire:ignore x-show="method === 'draw'" style="margin-top: .75rem;">
                            <div data-signature-canvas data-field-id="dsh-profile-pad" data-canvas-width="420"
                                 data-canvas-height="150" data-confirm-label="Use this signature" style="height: 200px;"></div>
                        </div>

                        <div x-show="method === 'upload'" x-cloak style="margin-top: .75rem;">
                            <input type="file" accept="image/png,image/jpeg" x-on:change="readFile($event.target.files?.[0])" />
                            <p class="dsh-meta" x-show="uploadError" x-text="uploadError" style="color: var(--dsh-bad);"></p>
                        </div>

                        <p class="dsh-meta" x-show="$wire.newSignature" x-cloak style="margin-top: .5rem !important;">Signature captured.</p>

                        <label class="dsh-meta" for="dsh-profile-cert" style="display: block; margin-top: .75rem;">Certificate password</label>
                        <input id="dsh-profile-cert" type="password" class="dsh-input" autocomplete="new-password"
                               placeholder="Protects your signing certificate" wire:model="newCertificatePassword" />

                        <div class="dsh-row">
                            <button type="button" class="dsh-btn dsh-btn--primary" wire:click="createSignature" wire:loading.attr="disabled" wire:target="createSignature">
                                Save signature
                            </button>
                        </div>
                    </div>
                @else
                    <p class="dsh-meta">No usable signature.</p>
                @endif
            </div>
        </div>

        {{-- ── This computer and devices (the package's Devices component) ── --}}
        <div class="dsh-card">
            <h3>This computer and devices</h3>
            <p class="dsh-meta" style="margin-bottom: .75rem !important;">
                One computer per person. If you lose it, pair a new one and pick your name again: a signature admin
                approves the move, usually the same working day.
            </p>
            @livewire('kukux-digital-signature.signing-devices')
        </div>

        {{-- ── Apps holding a copy ─────────────────────────────────────── --}}
        <div class="dsh-card">
            <h3>Apps that hold a copy of your signature</h3>
            @if ($apps->isEmpty())
                <p class="dsh-meta">None yet. An app gets a copy when you sign in to it or are named as a signatory there.</p>
            @else
                <table class="dsh-table">
                    <thead><tr><th>App</th><th>Since</th><th>Last copied</th></tr></thead>
                    <tbody>
                        @foreach ($apps as $holder)
                            <tr wire:key="dsh-app-{{ $holder->id }}">
                                <td>{{ $holder->app?->name ?? $holder->app?->client_id }}</td>
                                <td>{{ $holder->linked_at?->format('M j, Y') ?? '—' }}</td>
                                <td>{{ $holder->last_pulled_at?->diffForHumans() ?? 'not yet' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        {{-- ── Recent activity (the person's own audit trail) ─────────── --}}
        <div class="dsh-card">
            <h3>Recent activity</h3>
            <p class="dsh-meta" style="margin-bottom: .5rem !important;">Something here wasn't you? Contact the signature help desk right away.</p>
            @include('signature::hub.partials.audit-table', ['rows' => $activity])
        </div>
    </div>
</x-filament-panels::page>
