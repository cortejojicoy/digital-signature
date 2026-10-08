<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Kukux\DigitalSignature\Agent\AgentPairingService;
use Kukux\DigitalSignature\Agent\AgentServer;
use Kukux\DigitalSignature\Exceptions\UnregisteredDeviceException;
use Kukux\DigitalSignature\Models\AgentPairing;
use Kukux\DigitalSignature\Models\HubBlock;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\SigningDevice;

/**
 * "Pair this computer" for a visitor nobody knows yet (plan 1.1–1.2).
 *
 * A thin wrapper: the pairing itself is AgentPairingService's, unchanged
 * (start → agent lookup/claim → confirm → agent poll). What this adds is the
 * part a signed-in Devices tab gets for free from the session:
 *
 *   - a provisional account to pair (the protocol binds the user id early);
 *   - "only the browser that started it may confirm it": the account's
 *     identity carries that browser's BrowserBinding hash;
 *   - computers refused after a rejected claim (HubBlock, R8);
 *   - a per-IP limit on starting (R10);
 *   - on confirm, the browser is signed in as the provisional account, and
 *     EnsureIdentified sends it to "Who are you?".
 */
class GuestPairing
{
    public function __construct(
        private readonly AgentPairingService $pairings,
        private readonly IdentityService $identities,
    ) {}

    /**
     * @return array{pairing: string, user_code: string, link: string, expires_at: string}
     *
     * @throws IdentityException
     */
    public function start(Session $session, ?string $ip): array
    {
        if (! AgentServer::enabled()) {
            throw new IdentityException('Desktop agent pairing is not enabled on this server.', 'agent_disabled');
        }

        $key = 'signature-hub-pair:'.($ip ?? 'unknown');

        if (RateLimiter::tooManyAttempts($key, max(1, (int) config('signature.hub.pair_rate_limit', 10)))) {
            throw new IdentityException('Too many pairings from this network. Try again in an hour.', 'rate_limited');
        }

        RateLimiter::hit($key, 3600);

        ['user' => $user] = $this->identities->startProvisional(BrowserBinding::hash($session));

        $started = $this->pairings->start((int) $user->getKey());

        return [
            'pairing'    => $started['pairing']->uuid,
            'user_code'  => $started['user_code'],
            'link'       => $started['link'],
            'expires_at' => $started['pairing']->expires_at->toIso8601String(),
        ];
    }

    /**
     * What the waiting page shows: the code is still pending, or the agent
     * claimed it and here is the computer (AgentPairingService::describeClaim(),
     * the same details the Devices tab shows).
     *
     * @return array<string, mixed>|null  Null when this browser didn't start it.
     */
    public function describe(Session $session, string $uuid): ?array
    {
        $pairing = $this->ownPairing($session, $uuid);

        if ($pairing === null) {
            return null;
        }

        if (in_array($pairing->status, ['pending', 'awaiting_confirmation'], true) && $pairing->isExpired()) {
            $pairing->update(['status' => 'expired']);
        }

        $claim = $pairing->status === 'awaiting_confirmation' ? $this->pairings->describeClaim($pairing) : null;

        return [
            'status'     => $pairing->status,
            'expires_at' => $pairing->expires_at->toIso8601String(),
            'claim'      => $claim === null ? null : $claim + ['hub_blocked' => $this->isBlocked($pairing)],
        ];
    }

    /**
     * Confirm the claimed computer and sign this browser in as the account.
     *
     * @throws IdentityException
     */
    public function confirm(Session $session, string $uuid, ?string $deviceType = null): SigningDevice
    {
        $pairing = $this->ownPairing($session, $uuid);

        if ($pairing === null) {
            throw new IdentityException('Pairing not found.', 'not_found');
        }

        if ($this->isBlocked($pairing)) {
            $this->pairings->reject($pairing, (int) $pairing->user_id);

            throw new IdentityException('This computer cannot be paired with the hub right now. Contact the signature help desk.', 'computer_blocked');
        }

        try {
            $device = $this->pairings->confirm($pairing, (int) $pairing->user_id, $deviceType);
        } catch (InvalidArgumentException|UnregisteredDeviceException $e) {
            throw new IdentityException($e->getMessage(), 'not_confirmed');
        }

        Auth::loginUsingId((int) $pairing->user_id);
        $session->regenerate();

        return $device;
    }

    public function cancel(Session $session, string $uuid): void
    {
        if ($pairing = $this->ownPairing($session, $uuid)) {
            $this->pairings->reject($pairing, (int) $pairing->user_id);
        }
    }

    /**
     * The pairing, if it belongs to a provisional (unidentified) account
     * started by this browser. Anything else, including a real account's
     * pairing, is "not found".
     */
    private function ownPairing(Session $session, string $uuid): ?AgentPairing
    {
        $pairing = AgentPairing::query()->where('uuid', $uuid)->first();

        if ($pairing === null) {
            return null;
        }

        $identity = Identity::forUser((int) $pairing->user_id);

        return $identity !== null
            && $identity->status === Identity::UNIDENTIFIED
            && BrowserBinding::matches($session, $identity->session_hash)
            ? $pairing
            : null;
    }

    private function isBlocked(AgentPairing $pairing): bool
    {
        return HubBlock::isBlocked($pairing->claim['device']['hardware_id_hash'] ?? null);
    }
}
