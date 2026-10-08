{{-- Admin: Pending claims — see Kukux\DigitalSignature\Hub\Filament\Pages\PendingClaims. --}}
<x-filament-panels::page>
    @include('signature::hub.partials.styles')

    <div class="dsh dsh-stack">
        <x-filament::section heading="Claims to verify" description="Someone paired a computer and picked this name. Check their ID (in person or by video) before verifying.">
            @if ($claims->isEmpty())
                <p class="dsh-meta">No claims waiting.</p>
            @else
                <table class="dsh-table">
                    <thead><tr><th>Person</th><th>Computer</th><th>Claimed</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($claims as $row)
                            <tr wire:key="dsh-claim-{{ $row['identity']->id }}">
                                <td>
                                    <a class="dsh-name" href="{{ \Kukux\DigitalSignature\Hub\Filament\Pages\PersonnelProfile::getUrl(['key' => $row['identity']->personnel_key]) }}">{{ $row['person']?->name ?? $row['identity']->personnel_key }}</a>
                                    <span class="dsh-meta" style="display: block;">{{ collect([$row['person']?->empNo, $row['person']?->position, $row['person']?->unit])->filter()->join(' · ') }}</span>
                                    @if ($row['claims'] > 1)
                                        <span class="dsh-badge dsh-badge--warn">{{ $row['claims'] }} claims on this person</span>
                                    @endif
                                </td>
                                <td>{{ $row['computer'] ? $row['computer']->displayName().' ('.$row['computer']->deviceType()->label().')' : '—' }}</td>
                                <td style="white-space: nowrap;">{{ $row['identity']->claimed_at?->diffForHumans() }}</td>
                                <td style="white-space: nowrap;">
                                    <x-filament::button size="sm" color="success" wire:click="verify({{ $row['identity']->user_id }})"
                                        wire:confirm="Verify this claim? You checked their ID.">Verify</x-filament::button>
                                    <x-filament::button size="sm" color="danger" wire:click="reject({{ $row['identity']->user_id }})"
                                        wire:confirm="Reject this claim? The computer is released and refused for {{ (int) config('signature.hub.block_days', 30) }} days.">Reject</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        <x-filament::section heading="Moves to a new computer" description="The person is already linked to another computer. Their old computer approves on its own; approve here only when it's lost, after checking their ID.">
            @if ($transfers->isEmpty())
                <p class="dsh-meta">No moves waiting.</p>
            @else
                <table class="dsh-table">
                    <thead><tr><th>Person</th><th>Old computer</th><th>New computer</th><th>Asked</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($transfers as $row)
                            <tr wire:key="dsh-transfer-{{ $row['transfer']->id }}">
                                <td>
                                    <span class="dsh-name">{{ $row['person']?->name ?? $row['transfer']->personnel_key }}</span>
                                    <span class="dsh-meta" style="display: block;">{{ $row['person']?->empNo }}</span>
                                </td>
                                <td>
                                    {{ $row['oldComputer']?->displayName() ?? 'none (lost or released)' }}
                                    @if ($row['transfer']->agentJob)
                                        <span class="dsh-meta" style="display: block;">approval {{ $row['transfer']->agentJob->status }}</span>
                                    @endif
                                </td>
                                <td>{{ $row['newComputer']?->displayName() ?? '—' }}</td>
                                <td style="white-space: nowrap;">{{ $row['transfer']->created_at?->diffForHumans() }}</td>
                                <td style="white-space: nowrap;">
                                    <x-filament::button size="sm" color="success" wire:click="approveTransfer({{ $row['transfer']->id }})"
                                        wire:confirm="Move this person's signature to the new computer? The old computer is released and the certificate reissued.">Approve</x-filament::button>
                                    <x-filament::button size="sm" color="danger" wire:click="rejectTransfer({{ $row['transfer']->id }})"
                                        wire:confirm="Refuse this move?">Refuse</x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
