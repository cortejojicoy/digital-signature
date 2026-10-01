{{--
    My signing devices — see Kukux\DigitalSignature\Filament\Livewire\SigningDevices.

    Own scoped styles (dsd-*), like the launcher, so it renders the same
    whatever Tailwind utilities the host panel's theme happens to compile.
--}}
<div class="dsd" @if ($pairingUuid) wire:poll.2s="refreshPairing" @endif>
    <style>
        .dsd { --dsd-fg: #18181b; --dsd-muted: #71717a; --dsd-line: rgb(0 0 0 / .08); --dsd-soft: rgb(0 0 0 / .03);
               --dsd-ok: #15803d; --dsd-bad: #b91c1c; font-size: .875rem; color: var(--dsd-fg); }
        .dark .dsd { --dsd-fg: #f4f4f5; --dsd-muted: #a1a1aa; --dsd-line: rgb(255 255 255 / .1); --dsd-soft: rgb(255 255 255 / .04);
                     --dsd-ok: #4ade80; --dsd-bad: #f87171; }
        .dsd p { margin: 0; }
        .dsd-intro { color: var(--dsd-muted); font-size: .8125rem; line-height: 1.5; margin-bottom: .75rem !important; }
        .dsd-note { border-radius: .6rem; padding: .6rem .75rem; margin-bottom: .75rem; font-size: .8125rem; line-height: 1.45; }
        .dsd-note--ok { background: rgb(22 163 74 / .1); color: var(--dsd-ok); }
        .dsd-note--bad { background: rgb(220 38 38 / .1); color: var(--dsd-bad); }
        .dsd-list { border: 1px solid var(--dsd-line); border-radius: .75rem; overflow: hidden; }
        .dsd-item { display: flex; gap: .75rem; align-items: flex-start; padding: .75rem .9rem; }
        .dsd-item + .dsd-item { border-top: 1px solid var(--dsd-line); }
        .dsd-item--off { opacity: .55; }
        .dsd-icon { width: 1.25rem; height: 1.25rem; flex: none; margin-top: .1rem; color: var(--dsd-muted); }
        .dsd-main { flex: 1; min-width: 0; }
        .dsd-name { font-weight: 600; overflow-wrap: anywhere; }
        .dsd-meta { color: var(--dsd-muted); font-size: .75rem; line-height: 1.5; margin-top: .15rem !important; overflow-wrap: anywhere; }
        .dsd-mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
        .dsd-badges { display: flex; flex-wrap: wrap; gap: .3rem; margin-top: .35rem; }
        .dsd-badge { border-radius: 9999px; padding: .05rem .5rem; font-size: .6875rem; font-weight: 600;
                     background: var(--dsd-soft); border: 1px solid var(--dsd-line); }
        .dsd-badge--strong { background: rgb(22 163 74 / .1); border-color: rgb(22 163 74 / .25); color: var(--dsd-ok); }
        .dsd-actions { display: flex; gap: .35rem; flex: none; }
        .dsd-btn { cursor: pointer; border-radius: .5rem; padding: .35rem .7rem; font-size: .8125rem; font-weight: 600;
                   border: 1px solid var(--dsd-line); background: transparent; color: inherit; text-decoration: none;
                   display: inline-flex; align-items: center; gap: .35rem; }
        .dsd-btn:hover { background: var(--dsd-soft); }
        .dsd-btn--primary { background: var(--dsd-fg); border-color: var(--dsd-fg); color: #fff; }
        .dark .dsd-btn--primary { color: #18181b; }
        .dsd-btn--primary:hover { opacity: .9; background: var(--dsd-fg); }
        .dsd-btn--danger { color: var(--dsd-bad); }
        .dsd-input { width: 100%; border-radius: .5rem; border: 1px solid var(--dsd-line); background: transparent;
                     color: inherit; padding: .35rem .55rem; font-size: .875rem; }
        .dsd-agent { margin-top: 1rem; border: 1px solid var(--dsd-line); border-radius: .75rem; padding: .9rem; }
        .dsd-agent h4 { margin: 0 0 .25rem; font-size: .875rem; font-weight: 600; }
        .dsd-code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 1.75rem; font-weight: 700;
                    letter-spacing: .12em; margin: .6rem 0 !important; }
        .dsd-row { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: .75rem; }
        .dsd details summary { cursor: pointer; color: var(--dsd-muted); font-size: .8125rem; margin-top: .75rem; }
        .dsd-empty { color: var(--dsd-muted); padding: 1rem .9rem; }
    </style>

    <p class="dsd-intro">
        Browsers and computers that can sign as you. Each holds a key that never leaves it, and every
        signature records which one it came from.
    </p>

    @if ($flash)
        <div class="dsd-note dsd-note--ok" role="status">{{ $flash }}</div>
    @endif
    @if ($error)
        <div class="dsd-note dsd-note--bad" role="alert">{{ $error }}</div>
    @endif

    @php
        $active = $devices->where('status', '!=', 'revoked');
        $revoked = $devices->where('status', 'revoked');
    @endphp

    <div class="dsd-list">
        @forelse ($active as $device)
            <div class="dsd-item" wire:key="dsd-{{ $device->uuid }}">
                <x-filament::icon :icon="$device->icon()" class="dsd-icon" />

                <div class="dsd-main">
                    @if ($renaming === $device->uuid)
                        <form wire:submit="saveRename" style="display:flex;gap:.35rem;">
                            <input type="text" class="dsd-input" wire:model="renameLabel" maxlength="120" autofocus />
                            <button type="submit" class="dsd-btn dsd-btn--primary">Save</button>
                            <button type="button" class="dsd-btn" wire:click="cancelRename">Cancel</button>
                        </form>
                    @else
                        <p class="dsd-name">{{ $device->displayName() }}</p>
                    @endif

                    <p class="dsd-meta">
                        {{ $device->kind === 'agent'
                            ? trim(($device->platform ?? '').' · Kukux Sign Agent '.($device->agent_version ?? ''), ' ·')
                            : $device->describe() }}
                        · added {{ $device->created_at?->format('M j, Y') }}
                        · {{ $device->last_used_at ? 'last used '.$device->last_used_at->diffForHumans() : 'not used yet' }}
                    </p>
                    <p class="dsd-meta dsd-mono">{{ $device->shortFingerprint() }}</p>

                    <div class="dsd-badges">
                        <span @class(['dsd-badge', 'dsd-badge--strong' => in_array($device->protection, ['secure_enclave', 'tpm'], true)])>
                            {{ $device->protectionLabel() }}
                        </span>
                        @if ($device->user_presence)
                            <span class="dsd-badge">{{ $device->platform === 'Windows' ? 'Windows Hello' : 'Touch ID' }}</span>
                        @endif
                        @if ($device->attested)
                            <span class="dsd-badge dsd-badge--strong">Attested</span>
                        @endif
                        @if ($thisBrowser && $thisBrowser === $device->key_fingerprint)
                            <span class="dsd-badge">This browser</span>
                        @endif
                        @if ($device->status === 'pending')
                            <span class="dsd-badge">Pending approval</span>
                        @endif
                    </div>
                </div>

                @if ($renaming !== $device->uuid)
                    <div class="dsd-actions">
                        <button type="button" class="dsd-btn" wire:click="startRename('{{ $device->uuid }}')">Rename</button>
                        <button
                            type="button"
                            class="dsd-btn dsd-btn--danger"
                            wire:click="revoke('{{ $device->uuid }}')"
                            wire:confirm="Revoke {{ $device->displayName() }}? It will no longer be able to sign as you."
                        >Revoke</button>
                    </div>
                @endif
            </div>
        @empty
            <p class="dsd-empty">
                No devices yet. This browser registers itself the first time you sign with it.
            </p>
        @endforelse
    </div>

    @if ($revoked->isNotEmpty())
        <details>
            <summary>Revoked ({{ $revoked->count() }})</summary>
            <div class="dsd-list" style="margin-top:.5rem;">
                @foreach ($revoked as $device)
                    <div class="dsd-item dsd-item--off" wire:key="dsd-{{ $device->uuid }}">
                        <x-filament::icon :icon="$device->icon()" class="dsd-icon" />
                        <div class="dsd-main">
                            <p class="dsd-name">{{ $device->displayName() }}</p>
                            <p class="dsd-meta">Revoked {{ $device->revoked_at?->format('M j, Y H:i') }} · {{ $device->shortFingerprint() }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    @if ($agentEnabled)
        <div class="dsd-agent">
            @if ($pairing && $pairing->status === 'awaiting_confirmation' && $claim)
                <h4>Pair {{ $claim['label'] }}?</h4>
                <p class="dsd-meta">
                    {{ $claim['platform'] }} · {{ $claim['protection'] }}{{ $claim['presence'] ? ' · Touch ID / Windows Hello' : ' · no user presence' }}
                    · Kukux Sign Agent {{ $claim['version'] }}
                </p>
                @if ($claim['replaces'])
                    <p class="dsd-meta">This looks like the same computer as <strong>{{ $claim['replaces'] }}</strong>, which will be revoked.</p>
                @endif
                <p class="dsd-meta">Only confirm if this is the computer you just entered the code on.</p>
                <div class="dsd-row">
                    <button type="button" class="dsd-btn dsd-btn--primary" wire:click="confirmPairing">Pair this computer</button>
                    <button type="button" class="dsd-btn" wire:click="rejectPairing">That's not mine</button>
                </div>
            @elseif ($pairing && $userCode)
                <h4>Enter this code in Kukux Sign Agent</h4>
                <p class="dsd-code" aria-label="Pairing code">{{ $userCode }}</p>
                <p class="dsd-meta">
                    The agent also shows this site's address ({{ request()->getSchemeAndHttpHost() }}) — check it matches.
                    The code expires {{ $pairing->expires_at->diffForHumans() }}.
                </p>
                <div class="dsd-row">
                    <a class="dsd-btn dsd-btn--primary" href="{{ $pairLink }}">Open in Kukux Sign Agent</a>
                    <button type="button" class="dsd-btn" wire:click="rejectPairing">Cancel</button>
                </div>
            @else
                <h4>Sign from this computer's security chip</h4>
                <p class="dsd-meta">
                    Kukux Sign Agent keeps your signing key in the Secure Enclave (Mac) or TPM (Windows), and asks
                    for Touch ID or Windows Hello every time you sign.
                </p>
                <div class="dsd-row">
                    <button type="button" class="dsd-btn dsd-btn--primary" wire:click="startPairing">Pair desktop agent</button>
                    @if ($downloadUrl)
                        <a class="dsd-btn" href="{{ $downloadUrl }}" target="_blank" rel="noopener">Download the agent</a>
                    @endif
                </div>
            @endif
        </div>
    @endif
</div>
