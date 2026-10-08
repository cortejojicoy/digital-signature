{{-- "Who are you?" — see Kukux\DigitalSignature\Hub\Filament\Pages\Identify. --}}
<x-filament-panels::page>
    @include('signature::hub.partials.styles')

    <div class="dsh dsh-stack" style="max-width: 40rem; margin: 0 auto; width: 100%;" @if ($transfer) wire:poll.5s="checkTransfer" @endif>
        @if ($error)
            <div class="dsh-note dsh-note--bad" role="alert">{{ $error }}</div>
        @endif

        @if ($transfer)
            {{-- The person already has a signing computer: the move waits for it (plan 1.3, 1.6). --}}
            <div class="dsh-card">
                <h2>{{ $person?->name ?? 'You' }} already {{ $person ? 'has' : 'have' }} a signing computer{{ $oldComputer ? ': '.$oldComputer : '' }}</h2>

                @if ($transfer->agent_job_id && $oldComputer)
                    <p class="dsh-meta">
                        Approve the move on that computer: open this hub there and sign in, then click
                        <strong>Approve on this computer</strong> on your Profile. Kukux Sign Agent will ask for
                        Touch ID or Windows Hello.
                    </p>
                    <p class="dsh-meta">This page carries on by itself once it's approved.</p>
                @else
                    <p class="dsh-meta">
                        That computer is no longer paired, so a signature admin has to approve the move.
                        Bring an ID to the signature help desk, or ask for a video call. Moves are approved the same working day.
                    </p>
                @endif

                <p class="dsh-meta">
                    Lost the old computer? Ask the help desk: they can approve the move and release it.
                    Until then, urgent documents can go to a delegate.
                </p>

                <div class="dsh-row">
                    <button type="button" class="dsh-btn" wire:click="cancelTransfer"
                            wire:confirm="Cancel moving your signature to this computer?">That isn't me — cancel</button>
                </div>
            </div>
        @else
            <div class="dsh-card">
                <form wire:submit="search" class="dsh-row" style="margin-top: 0;">
                    <label for="dsh-identify-query" class="dsh-meta" style="width: 100%;">
                        Your name ({{ $minSearch }}+ letters) or your exact employee number
                    </label>
                    <input id="dsh-identify-query" type="search" class="dsh-input" style="flex: 1;" wire:model="query"
                           autocomplete="off" maxlength="80" autofocus />
                    <button type="submit" class="dsh-btn dsh-btn--primary" wire:loading.attr="disabled" wire:target="search">Search</button>
                </form>
                <p class="dsh-meta" style="margin-top: .5rem !important;">
                    Only active personnel are listed. {{ $quotaLeft }} {{ \Illuminate\Support\Str::plural('search', $quotaLeft) }} left.
                </p>
            </div>

            @if ($searched)
                <div class="dsh-list">
                    @forelse ($results as $index => $result)
                        <button type="button" class="dsh-item dsh-item--button" wire:key="dsh-person-{{ $index }}"
                                wire:click="choose({{ $index }})"
                                wire:confirm="Are you {{ $result['name'] }}? Picking someone else's name is recorded and refused.">
                            <span>
                                <span class="dsh-name">{{ $result['name'] }}</span>
                                <span class="dsh-meta" style="display: block;">{{ collect([$result['position'], $result['unit']])->filter()->join(' · ') }}</span>
                            </span>
                            <span class="dsh-btn">This is me</span>
                        </button>
                    @empty
                        <p class="dsh-empty">No one found. Check the spelling, or search by your employee number.</p>
                    @endforelse
                </div>
            @endif

            <p class="dsh-meta">
                A signature admin confirms who you are before you can sign documents or sign in to other apps.
                You can draw your signature meanwhile.
            </p>
        @endif

        <form method="POST" action="{{ filament()->getLogoutUrl() }}">
            @csrf
            <button type="submit" class="dsh-link dsh-meta">Sign out</button>
        </form>
    </div>
</x-filament-panels::page>
