{{--
    The hub's front door — see Kukux\DigitalSignature\Hub\Filament\Pages\Landing.

    Two flows, both plain fetch() + polling against the hub's JSON endpoints:

      Pair this computer   POST pairings → code + kukuxsign://pair link → poll
                           until the agent claims → show the computer exactly
                           as the Devices tab does → Confirm → signed in
      Sign in              POST login/challenges → match code + kukuxsign://login
                           link → poll until the agent approves → signed in

    The browser binding is the session cookie, so only this browser can see
    or confirm what it started.
--}}
<x-filament-panels::page.simple>
    @include('signature::hub.partials.styles')

    <div
        class="dsh"
        x-data="{
            endpoints: @js($endpoints),
            csrf: @js(csrf_token()),
            mode: 'idle',          // idle | pairing | claimed | signin | done
            error: null,
            busy: false,
            timer: null,

            pairing: null,         // {pairing, user_code, link, expires_at}
            claim: null,           // describeClaim() + hub_blocked
            deviceType: null,

            login: null,           // {uuid, match_code, link, expires_at}
            loginStatus: null,

            async call(method, url, body = null) {
                const response = await fetch(url, {
                    method,
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrf,
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: body === null ? null : JSON.stringify(body),
                })
                const data = await response.json().catch(() => ({}))
                if (! response.ok) throw new Error(data.message || 'Something went wrong. Try again.')
                return data
            },

            stop() { clearTimeout(this.timer); this.timer = null },

            reset() {
                this.stop()
                this.mode = 'idle'
                this.pairing = this.claim = this.login = this.loginStatus = this.deviceType = null
                this.busy = false
            },

            // ── Pair this computer ──────────────────────────────────────
            async startPairing() {
                this.reset(); this.error = null; this.busy = true
                try {
                    this.pairing = await this.call('POST', this.endpoints.pair)
                    this.mode = 'pairing'
                    this.pollPairing()
                } catch (e) { this.error = e.message } finally { this.busy = false }
            },

            async pollPairing() {
                if (! this.pairing) return
                try {
                    const state = await this.call('GET', this.endpoints.pair + '/' + this.pairing.pairing)
                    if (state.status === 'awaiting_confirmation' && state.claim) {
                        if (this.mode !== 'claimed') this.deviceType = state.claim.device_type
                        this.claim = state.claim
                        this.mode = 'claimed'
                    } else if (['expired', 'rejected', 'confirmed'].includes(state.status)) {
                        this.reset()
                        this.error = state.status === 'expired'
                            ? 'The pairing code expired. Start again to get a new one.'
                            : 'That pairing is no longer waiting.'
                        return
                    }
                } catch (e) { this.reset(); this.error = e.message; return }
                this.timer = setTimeout(() => this.pollPairing(), 2000)
            },

            async confirmPairing() {
                this.stop(); this.busy = true; this.error = null
                try {
                    const done = await this.call('POST', this.endpoints.pair + '/' + this.pairing.pairing + '/confirm', { device_type: this.deviceType })
                    this.mode = 'done'
                    window.location.href = done.redirect
                } catch (e) { this.reset(); this.error = e.message }
            },

            async cancelPairing() {
                const uuid = this.pairing?.pairing
                this.reset()
                if (uuid) await this.call('POST', this.endpoints.pair + '/' + uuid + '/cancel').catch(() => null)
            },

            // ── Sign in with your computer ──────────────────────────────
            async startSignIn() {
                this.reset(); this.error = null; this.busy = true
                try {
                    this.login = await this.call('POST', this.endpoints.login)
                    this.mode = 'signin'
                    this.loginStatus = 'pending'
                    window.location.href = this.login.link
                    this.pollSignIn()
                } catch (e) { this.error = e.message } finally { this.busy = false }
            },

            async pollSignIn() {
                if (! this.login) return
                try {
                    const state = await this.call('GET', this.endpoints.login + '/' + this.login.uuid)
                    this.loginStatus = state.status
                    if (state.status === 'approved') { this.mode = 'done'; window.location.href = state.redirect; return }
                    if (['rejected', 'expired', 'consumed'].includes(state.status)) {
                        this.reset()
                        this.error = state.status === 'rejected'
                            ? 'The sign-in was declined on your computer.'
                            : 'The sign-in request expired. Try again.'
                        return
                    }
                } catch (e) { this.reset(); this.error = e.message; return }
                this.timer = setTimeout(() => this.pollSignIn(), 1500)
            },
        }"
        x-on:beforeunload.window="stop()"
    >
        <div class="dsh-note dsh-note--bad" role="alert" x-show="error" x-text="error" x-cloak style="margin-bottom: 1rem;"></div>

        @unless ($agentEnabled)
            <div class="dsh-note dsh-note--warn">Desktop agent pairing is not enabled on this server yet.</div>
        @endunless

        {{-- ── Choose ─────────────────────────────────────────────────── --}}
        <div x-show="mode === 'idle'">
            <p class="dsh-meta" style="margin-bottom: 1rem !important;">
                Kukux Sign Agent keeps your signing key in this computer's Secure Enclave (Mac) or TPM (Windows),
                and asks for Touch ID or Windows Hello every time you sign or sign in.
            </p>

            <button type="button" class="dsh-btn dsh-btn--primary dsh-btn--block" x-on:click="startPairing()" x-bind:disabled="busy || {{ $agentEnabled ? 'false' : 'true' }}">
                Pair this computer
            </button>

            <p class="dsh-divider">Already paired?</p>

            <button type="button" class="dsh-btn dsh-btn--block" x-on:click="startSignIn()" x-bind:disabled="busy || {{ $agentEnabled ? 'false' : 'true' }}">
                Sign in with your computer
            </button>

            @if ($downloads !== [])
                <div class="dsh-row" style="justify-content: center; margin-top: 1.25rem;">
                    <span class="dsh-meta">Get Kukux Sign Agent:</span>
                    @foreach ($downloads as $platform => $url)
                        <a class="dsh-link dsh-meta" href="{{ $url }}" rel="noopener">{{ match ($platform) { 'mac' => 'macOS', 'windows' => 'Windows', default => ucfirst((string) $platform) } }}</a>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- ── Pairing: the code (same as the Devices tab) ────────────── --}}
        <div x-show="mode === 'pairing'" x-cloak>
            <h3>Enter this code in Kukux Sign Agent</h3>
            <p class="dsh-code" aria-label="Pairing code" x-text="pairing?.user_code"></p>
            <p class="dsh-meta">
                The agent also shows this site's address ({{ request()->getSchemeAndHttpHost() }}) — check it matches.
                Waiting for the agent…
            </p>
            <div class="dsh-row">
                <a class="dsh-btn dsh-btn--primary" x-bind:href="pairing?.link">Open in Kukux Sign Agent</a>
                <button type="button" class="dsh-btn" x-on:click="cancelPairing()">Cancel</button>
            </div>
        </div>

        {{-- ── Pairing: the claimed computer (describeClaim) ──────────── --}}
        <div x-show="mode === 'claimed'" x-cloak>
            <template x-if="claim && (claim.hub_blocked || claim.blocked_by)">
                <div>
                    <h3>This computer can't be paired right now</h3>
                    <p class="dsh-meta" x-show="claim.hub_blocked">
                        A claim made from this computer was rejected. Contact the signature help desk.
                    </p>
                    <p class="dsh-meta" x-show="! claim.hub_blocked">
                        One account can be paired with only one computer. Remove <strong x-text="claim.blocked_by"></strong> first.
                    </p>
                    <div class="dsh-row">
                        <button type="button" class="dsh-btn" x-on:click="cancelPairing()">Cancel</button>
                    </div>
                </div>
            </template>

            <template x-if="claim && ! claim.hub_blocked && ! claim.blocked_by">
                <div>
                    <h3 x-text="claim.replaces ? 'Re-pair ' + claim.replaces + '?' : 'Pair ' + claim.label + '?'"></h3>
                    <p class="dsh-meta">
                        <span x-text="claim.platform"></span> · <span x-text="claim.protection"></span><span
                            x-text="claim.presence ? ' · Touch ID / Windows Hello' : ' · no user presence'"></span>
                        · Kukux Sign Agent <span x-text="claim.version"></span>
                    </p>

                    <label class="dsh-field">
                        <span>This computer is a</span>
                        <select class="dsh-select" x-model="deviceType" x-bind:disabled="claim.type_locked">
                            @foreach ($deviceTypes as $group => $types)
                                <optgroup label="{{ $group }}">
                                    @foreach ($types as $value => $label)
                                        <option value="{{ $value }}">{{ $label }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                    </label>

                    <p class="dsh-meta" x-show="! claim.identified" style="margin-top: .6rem !important;">
                        We couldn't identify this computer. If it's already paired, remove the old entry.
                    </p>

                    <p class="dsh-meta" style="margin-top: .6rem !important;">Only confirm if this is the computer you just entered the code on.</p>
                    <div class="dsh-row">
                        <button type="button" class="dsh-btn dsh-btn--primary" x-on:click="confirmPairing()" x-bind:disabled="busy">
                            Pair this computer
                        </button>
                        <button type="button" class="dsh-btn" x-on:click="cancelPairing()">That's not mine</button>
                    </div>
                </div>
            </template>
        </div>

        {{-- ── Sign in: the match code ────────────────────────────────── --}}
        <div x-show="mode === 'signin'" x-cloak>
            <h3>Check the code on your computer</h3>
            <p class="dsh-code" aria-label="Match code" x-text="login?.match_code"></p>
            <p class="dsh-meta" x-show="loginStatus === 'pending'">
                Kukux Sign Agent should open. If it doesn't, open it with the button below.
            </p>
            <p class="dsh-meta" x-show="loginStatus === 'claimed'" x-cloak>
                Your computer is asking for Touch ID or Windows Hello. Approve only if it shows this same code.
            </p>
            <p class="dsh-meta">
                Not paired yet? The agent will say so: <button type="button" class="dsh-link" x-on:click="startPairing()">pair this computer</button> instead.
            </p>
            <div class="dsh-row">
                <a class="dsh-btn dsh-btn--primary" x-bind:href="login?.link">Open in Kukux Sign Agent</a>
                <button type="button" class="dsh-btn" x-on:click="reset()">Cancel</button>
            </div>
        </div>

        <div x-show="mode === 'done'" x-cloak class="dsh-note dsh-note--ok">Signed in. Taking you there…</div>

        @if ($breakGlass)
            <p class="dsh-meta" style="text-align: center; margin-top: 1.5rem !important;">
                <a class="dsh-link" href="{{ route('signature.hub.break-glass') }}">Break-glass sign-in</a>
            </p>
        @endif
    </div>
</x-filament-panels::page.simple>
