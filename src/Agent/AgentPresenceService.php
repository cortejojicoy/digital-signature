<?php

namespace Kukux\DigitalSignature\Agent;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Models\SigningDevice;

/**
 * "Is this the computer your account is paired with?"
 *
 * Under `devices.agent.approval = enforce`, signing a document needs the
 * signer's paired computer, so the page should not offer a Sign button on any
 * other. A web page cannot ask the agent directly — the agent only talks to
 * the server — so the page asks the server for a check, opens its
 * `kukuxsign://presence/...` link, and the agent on *this* computer, if there
 * is one, reports in:
 *
 *   start()   web    — a one-time check and its link
 *   report()  agent  — "this computer, paired as this account, is here"
 *   poll()    web    — pending | confirmed | other_account | expired;
 *                      confirmed is remembered in the session
 *   here()    server — the paired computer this session proved it is on
 *
 * Nothing answering means this is not the paired computer (or the agent is
 * not running). The approval job is still what authorizes a signature; this
 * only decides whether this browser may ask for one.
 *
 * Checks live in the cache: they last a minute or two and carry no record
 * worth keeping.
 */
class AgentPresenceService
{
    public const SESSION_KEY = 'signature.agent_presence';

    /** Whether signing needs the paired computer at all. */
    public function required(): bool
    {
        return AgentServer::enabled()
            && config('signature.devices.agent.approval', 'prefer') === 'enforce';
    }

    /** The computer this account is paired with, if any. */
    public function pairedComputer(int $userId): ?SigningDevice
    {
        return SigningDevice::query()
            ->where('user_id', $userId)
            ->where('kind', 'agent')
            ->active()
            ->latest('last_used_at')
            ->latest('id')
            ->first();
    }

    /**
     * @return array{uuid: string, link: string, expires_in: int}
     */
    public function start(int $userId): array
    {
        $uuid = (string) Str::uuid();
        $token = AgentServer::token();
        $ttl = $this->checkTtl();

        Cache::put($this->key($uuid), [
            'user_id'    => $userId,
            'token_hash' => hash('sha256', $token),
            'status'     => 'pending',
            'device_id'  => null,
            'expires_at' => now()->addSeconds($ttl)->getTimestamp(),
        ], $ttl + 60);

        return [
            'uuid'       => $uuid,
            'link'       => sprintf('%s://presence/%s?t=%s&s=%s', AgentServer::scheme(), $uuid, $token, AgentServer::id()),
            'expires_in' => $ttl,
        ];
    }

    /**
     * The agent on some computer answering a check.
     *
     * @return array{status: string}
     *
     * @throws AgentApiException
     */
    public function report(SigningDevice $device, string $uuid, string $linkToken): array
    {
        return Cache::lock($this->key($uuid).':lock', 5)->block(3, function () use ($device, $uuid, $linkToken) {
            $check = Cache::get($this->key($uuid));

            if (! is_array($check)) {
                throw new AgentApiException(404, 'presence_not_found', 'Presence check not found.');
            }

            if ($check['status'] !== 'pending' || $check['expires_at'] < now()->getTimestamp()) {
                throw new AgentApiException(409, 'presence_unavailable', 'This presence check is no longer open.');
            }

            if (! hash_equals((string) $check['token_hash'], hash('sha256', $linkToken))) {
                throw new AgentApiException(403, 'invalid_link_token', 'This link is not valid for that presence check.');
            }

            $mine = (int) $device->user_id === (int) $check['user_id'];

            $check['status'] = $mine ? 'confirmed' : 'other_account';
            $check['device_id'] = $device->id;
            $check['token_hash'] = '';   // single use

            Cache::put($this->key($uuid), $check, $this->checkTtl() + 60);

            if (! $mine) {
                throw new AgentApiException(403, 'wrong_account', 'This computer is paired with another account on this app.');
            }

            return ['status' => 'confirmed'];
        });
    }

    /**
     * What the waiting page polls. A confirmed check is remembered in the
     * session, so reopening documents doesn't wake the agent every time.
     *
     * @return array{status: string}|null  Null when the check is not this user's.
     */
    public function poll(int $userId, string $uuid): ?array
    {
        $check = Cache::get($this->key($uuid));

        if (! is_array($check) || (int) $check['user_id'] !== $userId) {
            return null;
        }

        $status = $check['status'];

        if ($status === 'pending' && $check['expires_at'] < now()->getTimestamp()) {
            $status = 'expired';
        }

        if ($status === 'confirmed') {
            $device = SigningDevice::query()->whereKey($check['device_id'])->where('user_id', $userId)->first();

            if ($device?->isActive() && $device->kind === 'agent') {
                $this->remember($userId, $device);
            } else {
                $status = 'expired';
            }
        }

        return ['status' => $status];
    }

    /**
     * The paired computer this session has proved it is on, still active and
     * still the account's computer.
     */
    public function here(int $userId): ?SigningDevice
    {
        if (! app()->bound('request') || ! request()->hasSession()) {
            return null;
        }

        $seen = request()->session()->get(self::SESSION_KEY);

        if (! is_array($seen) || (int) ($seen['user_id'] ?? 0) !== $userId) {
            return null;
        }

        if ((int) ($seen['at'] ?? 0) + $this->validFor() < now()->getTimestamp()) {
            return null;
        }

        $paired = $this->pairedComputer($userId);

        return $paired !== null && (int) $paired->id === (int) ($seen['device_id'] ?? 0) ? $paired : null;
    }

    /**
     * What the signing page needs to decide whether its Sign button works.
     *
     * @return array<string, mixed>
     */
    public function state(int $userId): array
    {
        if (! $this->required()) {
            return ['required' => false];
        }

        $paired = $this->pairedComputer($userId);

        return [
            'required'   => true,
            'paired'     => $paired !== null,
            'computer'   => $paired?->displayName(),
            'here'       => $paired !== null && $this->here($userId) !== null,
            'checkUrl'   => route('signature.agent.web.presence.start'),
            'devicesUrl' => config('signature.devices.agent.devices_url') ?: null,
            'downloadUrl' => config('signature.devices.agent.download_url') ?: null,
        ];
    }

    /**
     * Why this user may not sign from this browser right now, or null when
     * they may. The same wording the page shows.
     */
    public function refusal(int $userId): ?string
    {
        if (! $this->required()) {
            return null;
        }

        $paired = $this->pairedComputer($userId);

        if ($paired === null) {
            return 'You cannot sign yet: pair Kukux Sign Agent with your computer first.';
        }

        if ($this->here($userId) === null) {
            return "You are prohibited from signing on this computer. Your account is paired with {$paired->displayName()}; sign from that computer.";
        }

        return null;
    }

    private function remember(int $userId, SigningDevice $device): void
    {
        request()->session()->put(self::SESSION_KEY, [
            'user_id'   => $userId,
            'device_id' => $device->id,
            'at'        => now()->getTimestamp(),
        ]);
    }

    private function key(string $uuid): string
    {
        return 'signature.agent.presence.'.$uuid;
    }

    private function checkTtl(): int
    {
        return max(10, (int) config('signature.devices.agent.checkin_ttl', 90));
    }

    private function validFor(): int
    {
        return max(60, (int) config('signature.devices.agent.checkin_valid_for', 900));
    }
}
