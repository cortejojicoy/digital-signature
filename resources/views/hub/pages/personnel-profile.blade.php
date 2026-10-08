{{-- Admin: one person — see Kukux\DigitalSignature\Hub\Filament\Pages\PersonnelProfile. --}}
<x-filament-panels::page>
    @include('signature::hub.partials.styles')

    <div class="dsh dsh-stack">
        <div class="dsh-grid dsh-grid--2">
            <div class="dsh-card">
                <h3>Identity</h3>
                <dl class="dsh-dl">
                    <dt>Name</dt><dd>{{ $person?->name ?? '—' }}</dd>
                    <dt>Employee no.</dt><dd class="dsh-mono">{{ $person?->empNo ?? '—' }}</dd>
                    <dt>Unit</dt><dd>{{ $person?->unit ?? '—' }}</dd>
                    <dt>Position</dt><dd>{{ $person?->position ?? '—' }}</dd>
                    <dt>Registry</dt><dd>{{ $person ? ($person->active ? 'Active' : 'Inactive') : 'Not in the registry' }}</dd>
                    <dt>Status</dt><dd>{{ $status }}</dd>
                    @if ($identity)
                        <dt>Account</dt><dd>#{{ $identity->user_id }} · {{ $identity->status }}</dd>
                        <dt>Claimed</dt><dd>{{ $identity->claimed_at?->format('M j, Y H:i') ?? '—' }}</dd>
                        <dt>Verified</dt><dd>{{ $identity->verified_at?->format('M j, Y H:i') ?? '—' }}</dd>
                    @endif
                </dl>
                @if ($identity && $identity->status === \Kukux\DigitalSignature\Models\Identity::PENDING)
                    <div class="dsh-row">
                        <x-filament::button wire:click="verify" wire:confirm="Verify that this account belongs to {{ $person?->name }}? Check their ID first." color="success">Verify</x-filament::button>
                        <x-filament::button wire:click="reject" wire:confirm="Reject this claim? The computer is released and refused for {{ (int) config('signature.hub.block_days', 30) }} days." color="danger">Reject</x-filament::button>
                    </div>
                @endif
            </div>

            <div class="dsh-card">
                <h3>Signature</h3>
                @if ($signature)
                    @if ($signature->status !== 'revoked')
                        <img class="dsh-sigimg" src="{{ $signature->getTemporaryImageUrl() }}" alt="Signature" />
                    @endif
                    <dl class="dsh-dl">
                        <dt>Status</dt><dd>{{ $signature->status }}</dd>
                        <dt>SHA-256</dt><dd class="dsh-mono">{{ $signature->image_hash }}</dd>
                        <dt>Added</dt><dd>{{ $signature->created_at?->format('M j, Y H:i') }}</dd>
                    </dl>
                    @if ($signature->status !== 'revoked')
                        <div class="dsh-row">
                            <x-filament::button wire:click="revokeSignature" color="danger"
                                wire:confirm="Revoke this signature and certificate? Apps holding a copy delete it.">Revoke signature</x-filament::button>
                        </div>
                    @endif
                @else
                    <p class="dsh-meta">No signature.</p>
                @endif

                <h3 style="margin-top: 1rem;">Certificate</h3>
                @forelse ($certificates as $cert)
                    <p class="dsh-meta dsh-mono">
                        {{ $cert->fingerprint }} · {{ $cert->revoked_at ? 'revoked '.$cert->revoked_at->format('M j, Y') : 'valid until '.$cert->expires_at?->format('M j, Y') }}
                    </p>
                @empty
                    <p class="dsh-meta">None issued yet (issued at the first signature).</p>
                @endforelse
            </div>
        </div>

        <div class="dsh-card">
            <h3>Devices</h3>
            @if ($devices->isEmpty())
                <p class="dsh-meta">No devices.</p>
            @else
                <table class="dsh-table">
                    <thead><tr><th>Device</th><th>Protection</th><th>Paired</th><th>Last used</th><th>Hardware</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($devices as $device)
                            <tr wire:key="dsh-dev-{{ $device->uuid }}">
                                <td>
                                    <span class="dsh-name">{{ $device->displayName() }}</span>
                                    <span class="dsh-meta" style="display: block;">{{ $device->deviceType()->label() }} · {{ $device->platform }} · {{ $device->model }} · {{ $device->status }}</span>
                                </td>
                                <td>{{ $device->protectionLabel() }}{{ $device->user_presence ? ' · '.($device->platform === 'Windows' ? 'Windows Hello' : 'Touch ID') : '' }}</td>
                                <td>{{ $device->created_at?->format('M j, Y') }}</td>
                                <td>{{ $device->last_used_at?->diffForHumans() ?? 'never' }}{{ $device->last_used_ip ? ' · '.$device->last_used_ip : '' }}</td>
                                <td class="dsh-mono">{{ $device->hardware_id_hash ? \Illuminate\Support\Str::limit($device->hardware_id_hash, 16, '…') : '—' }}</td>
                                <td>
                                    @if ($device->kind === 'agent' && $device->isActive())
                                        <x-filament::button size="sm" color="danger" wire:click="releaseDevice('{{ $device->uuid }}')"
                                            wire:confirm="Release {{ $device->displayName() }}? It will no longer be able to sign.">Release</x-filament::button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </div>

        <div class="dsh-card">
            <h3>Apps holding a copy</h3>
            @forelse ($apps as $holder)
                <p class="dsh-meta">{{ $holder->app?->name }} · since {{ $holder->linked_at?->format('M j, Y') ?? '—' }} · last copied {{ $holder->last_pulled_at?->diffForHumans() ?? 'not yet' }}</p>
            @empty
                <p class="dsh-meta">None.</p>
            @endforelse
        </div>

        <div class="dsh-card">
            <h3>Audit trail</h3>
            @include('signature::hub.partials.audit-table', ['rows' => $trail])
        </div>
    </div>
</x-filament-panels::page>
