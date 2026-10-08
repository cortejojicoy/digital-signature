{{-- Admin: Apps — see Kukux\DigitalSignature\Hub\Filament\Pages\Apps. --}}
<x-filament-panels::page>
    @include('signature::hub.partials.styles')

    <div class="dsh dsh-stack">
        @if ($secrets)
            <div class="dsh-note dsh-note--warn" role="alert">
                <strong>Copy these now for {{ $secrets['app'] }}: they are not shown again.</strong>
                <dl class="dsh-dl">
                    @isset($secrets['client_secret'])
                        <dt>SIGNATURE_HUB_CLIENT_SECRET</dt><dd class="dsh-mono">{{ $secrets['client_secret'] }}</dd>
                    @endisset
                    @isset($secrets['webhook_secret'])
                        <dt>SIGNATURE_HUB_WEBHOOK_SECRET</dt><dd class="dsh-mono">{{ $secrets['webhook_secret'] }}</dd>
                    @endisset
                </dl>
                <div class="dsh-row"><button type="button" class="dsh-btn" wire:click="dismissSecrets">I've copied them</button></div>
            </div>
        @endif

        <x-filament::section heading="Registered apps">
            @if ($apps->isEmpty())
                <p class="dsh-meta">No apps yet.</p>
            @else
                <table class="dsh-table">
                    <thead><tr><th>App</th><th>Scopes</th><th>Webhook</th><th>Holders</th><th></th></tr></thead>
                    <tbody>
                        @foreach ($apps as $app)
                            <tr wire:key="dsh-hubapp-{{ $app->id }}">
                                <td>
                                    <span class="dsh-name">{{ $app->name }}</span>
                                    <span class="dsh-meta dsh-mono" style="display: block;">{{ $app->client_id }}</span>
                                    @unless ($app->active)<span class="dsh-badge dsh-badge--bad">inactive</span>@endunless
                                </td>
                                <td>{{ implode(', ', (array) $app->scopes) }}</td>
                                <td>
                                    <span class="dsh-mono">{{ $app->webhook_url ?: '—' }}</span>
                                    @if ($app->failing_count > 0)
                                        <span class="dsh-badge dsh-badge--bad">{{ $app->failing_count }} failing</span>
                                    @endif
                                </td>
                                <td>{{ $app->holders_count }}</td>
                                <td style="white-space: nowrap;">
                                    <x-filament::button size="sm" color="gray" wire:click="showDeliveries({{ $app->id }})">Deliveries</x-filament::button>
                                    <x-filament::button size="sm" color="gray" wire:click="rotateSecret({{ $app->id }})"
                                        wire:confirm="Rotate {{ $app->client_id }}'s client secret? The app stops working until it has the new one.">Rotate secret</x-filament::button>
                                    <x-filament::button size="sm" color="gray" wire:click="rotateWebhookSecret({{ $app->id }})"
                                        wire:confirm="Rotate {{ $app->client_id }}'s webhook secret?">Rotate webhook secret</x-filament::button>
                                    <x-filament::button size="sm" :color="$app->active ? 'danger' : 'success'" wire:click="toggleActive({{ $app->id }})">
                                        {{ $app->active ? 'Deactivate' : 'Activate' }}
                                    </x-filament::button>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>

        @if ($selectedApp)
            <x-filament::section heading="Webhook deliveries" description="The latest 25. Undelivered ones retry with backoff from 1 minute up to 24 hours.">
                @if ($deliveries->isEmpty())
                    <p class="dsh-meta">None.</p>
                @else
                    <table class="dsh-table">
                        <thead><tr><th>Event</th><th>Created</th><th>Attempts</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                            @foreach ($deliveries as $webhook)
                                <tr wire:key="dsh-hook-{{ $webhook->id }}">
                                    <td><span class="dsh-mono">{{ $webhook->event }}</span></td>
                                    <td>{{ $webhook->created_at?->format('M j, H:i') }}</td>
                                    <td>{{ $webhook->attempts }}</td>
                                    <td>
                                        @if ($webhook->delivered_at)
                                            <span class="dsh-badge dsh-badge--ok">delivered {{ $webhook->delivered_at->diffForHumans() }}</span>
                                        @else
                                            <span class="dsh-badge dsh-badge--warn">{{ $webhook->last_status ? 'HTTP '.$webhook->last_status : 'pending' }}</span>
                                            <span class="dsh-meta" style="display: block;">{{ \Illuminate\Support\Str::limit((string) $webhook->last_error, 80) }}</span>
                                        @endif
                                    </td>
                                    <td><x-filament::button size="sm" color="gray" wire:click="redeliver({{ $webhook->id }})">Redeliver</x-filament::button></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </x-filament::section>
        @endif

        <x-filament::section heading="Register an app">
            <form wire:submit="create" class="dsh-stack">
                <div class="dsh-grid dsh-grid--2">
                    <label class="dsh-meta">Client id (e.g. performance)
                        <input type="text" class="dsh-input" wire:model="clientId" maxlength="64" required />
                    </label>
                    <label class="dsh-meta">Name
                        <input type="text" class="dsh-input" wire:model="name" maxlength="120" required />
                    </label>
                </div>
                <label class="dsh-meta" style="display: block;">Redirect URIs (one per line, exact)
                    <textarea class="dsh-input" rows="2" wire:model="redirectUris" placeholder="https://performance.uplb.edu.ph/signature/hub/callback"></textarea>
                </label>
                <label class="dsh-meta" style="display: block;">Webhook URL
                    <input type="url" class="dsh-input" wire:model="webhookUrl" placeholder="https://performance.uplb.edu.ph/signature/hub/webhook" />
                </label>
                <div><x-filament::button type="submit">Register</x-filament::button></div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>
