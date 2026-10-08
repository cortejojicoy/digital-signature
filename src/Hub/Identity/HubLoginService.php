<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Agent\AgentApiException;
use Kukux\DigitalSignature\Agent\AgentJobService;
use Kukux\DigitalSignature\Agent\AgentServer;
use Kukux\DigitalSignature\Events\AgentJobUpdated;
use Kukux\DigitalSignature\Models\AgentJob;
use Kukux\DigitalSignature\Models\HubBlock;
use Kukux\DigitalSignature\Models\HubLogin;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;

/**
 * "Sign in with your computer": the hub's only sign-in (plan 1.5, R3).
 *
 *   start()   browser  a challenge bound to this browser, with a match code
 *                      and a kukuxsign://login/<uuid>?t=…&s=… link
 *   claim()   agent    the paired computer that opened the link takes it;
 *                      its account is who signs in (usernameless). It gets a
 *                      normal agent job, purpose `login`, and shows the code
 *   (agent)            Touch ID / Windows Hello, then the existing
 *                      jobs/{uuid}/complete or /reject (AgentJobService)
 *   jobUpdated()       completed → approved, rejected → rejected
 *   poll()    browser  the same browser only; approved → signed in, once
 *
 * The proof the agent signs covers sha256(uuid|match_code|browser hash), so
 * an approval can't be replayed onto another browser's challenge.
 */
class HubLoginService
{
    public function __construct(private readonly AgentJobService $jobs) {}

    /**
     * @return array{uuid: string, match_code: string, link: string, expires_at: string}
     */
    public function start(Session $session, ?string $ip, ?string $userAgent): array
    {
        $token = AgentServer::token();

        $login = HubLogin::create([
            'uuid'            => (string) Str::uuid(),
            'session_hash'    => BrowserBinding::hash($session),
            'match_code'      => sprintf('%02d-%02d', random_int(10, 99), random_int(10, 99)),
            'link_token_hash' => hash('sha256', $token),
            'status'          => 'pending',
            'ip'              => $ip,
            'user_agent'      => Str::limit((string) $userAgent, 1000, ''),
            'expires_at'      => now()->addSeconds((int) config('signature.hub.login_ttl', 120)),
        ]);

        return [
            'uuid'       => $login->uuid,
            'match_code' => $login->match_code,
            'link'       => sprintf('%s://login/%s?t=%s&s=%s', AgentServer::scheme(), $login->uuid, $token, AgentServer::id()),
            'expires_at' => $login->expires_at->toIso8601String(),
        ];
    }

    /**
     * The browser's poll. Approved: this browser is signed in as the account
     * whose computer approved, and the challenge is spent.
     *
     * @return array{status: string, redirect?: string}|null  Null when this
     *         browser didn't start the challenge.
     */
    public function poll(Session $session, string $uuid): ?array
    {
        $login = HubLogin::query()->where('uuid', $uuid)->first();

        if ($login === null || ! BrowserBinding::matches($session, $login->session_hash)) {
            return null;
        }

        $this->expireIfDue($login);

        if ($login->status !== 'approved') {
            return ['status' => $login->status];
        }

        $consumed = HubLogin::query()->whereKey($login->id)->where('status', 'approved')
            ->update(['status' => 'consumed', 'consumed_at' => now()]);

        if ($consumed !== 1 || ! $this->mayLogIn((int) $login->user_id)) {
            return ['status' => 'rejected'];
        }

        Auth::loginUsingId((int) $login->user_id);
        $session->regenerate();

        return ['status' => 'approved', 'redirect' => app(HubRedirector::class)->afterSignIn(Auth::user())];
    }

    /**
     * The agent opened the link: bind the challenge to its account and hand
     * it a login job, already claimed by this computer.
     *
     * @return array<string, mixed>  AgentJobService::payload()
     *
     * @throws AgentApiException
     */
    public function claim(SigningDevice $device, string $uuid, string $linkToken): array
    {
        return DB::transaction(function () use ($device, $uuid, $linkToken) {
            $login = HubLogin::query()->where('uuid', $uuid)->lockForUpdate()->first();

            if ($login === null) {
                throw new AgentApiException(404, 'login_not_found', 'Sign-in request not found.');
            }

            if ($this->expireIfDue($login) || $login->status !== 'pending') {
                throw new AgentApiException(409, 'login_unavailable', "This sign-in request is {$login->status}.");
            }

            if ($login->link_token_hash === null || ! hash_equals($login->link_token_hash, hash('sha256', $linkToken))) {
                throw new AgentApiException(403, 'invalid_link_token', 'This link is not valid for that sign-in request.');
            }

            if (! $this->mayLogIn((int) $device->user_id) || HubBlock::isBlocked($device->hardware_id_hash)) {
                throw new AgentApiException(409, 'login_unavailable', 'This computer\'s account can no longer sign in to the hub.');
            }

            [$job] = $this->jobs->create(
                (int) $device->user_id,
                'login',
                'Sign in to '.config('app.name'),
                self::payloadHash($login),
                [
                    'ttl'  => max(1, (int) now()->diffInSeconds($login->expires_at, false)),
                    'meta' => ['login' => [
                        'match_code' => $login->match_code,
                        'browser'    => BrowserLabel::from($login->user_agent),
                        'ip'         => (string) $login->ip,
                    ]],
                ],
            );

            // Claimed by this computer straight away: the link was its claim.
            $job->update([
                'status'          => 'claimed',
                'device_id'       => $device->id,
                'link_token_hash' => null,
                'claimed_at'      => now(),
            ]);

            $login->update([
                'user_id'         => $device->user_id,
                'agent_job_id'    => $job->id,
                'status'          => 'claimed',
                'link_token_hash' => null,     // single use
            ]);

            event(new AgentJobUpdated($job));

            return $this->jobs->payload($job->refresh());
        });
    }

    /** AgentJobUpdated for a `login` job (HandleIdentityAgentJobs). */
    public function jobUpdated(AgentJob $job): void
    {
        $login = HubLogin::query()->where('agent_job_id', $job->id)->first();

        if ($login === null || $login->status !== 'claimed') {
            return;
        }

        $device = $job->device;

        match ($job->status) {
            'completed' => $this->approve($login, $job, $device),
            'rejected'  => $this->decline($login, $job, $device),
            'expired'   => $login->update(['status' => 'expired']),
            default     => null,
        };
    }

    public static function payloadHash(HubLogin $login): string
    {
        return hash('sha256', implode('|', [$login->uuid, $login->match_code, $login->session_hash]));
    }

    private function approve(HubLogin $login, AgentJob $job, ?SigningDevice $device): void
    {
        $login->update(['status' => 'approved', 'approved_at' => now()]);

        $agentIp = request()->ip();

        SignatureAudit::record(SignatureAudit::LOGIN_APPROVED, [
            'subject_user_id' => $login->user_id,
            'actor_user_id'   => $login->user_id,
            'device_id'       => $device?->id,
            'ip'              => $login->ip,
            'user_agent'      => $login->user_agent,
            'context'         => array_filter([
                'challenge'  => $login->uuid,
                'job'        => $job->uuid,
                'purpose'    => 'login',
                'browser'    => BrowserLabel::from($login->user_agent),
                'agent_ip'   => $agentIp,
                // R3 "detect": the approving computer isn't on the browser's network.
                'network_differs' => $agentIp !== null && $login->ip !== null && ! self::sameNetwork($agentIp, $login->ip) ? true : null,
                'presence'   => $device?->user_presence ? ($device->platform === 'Windows' ? 'Windows Hello' : 'Touch ID') : null,
            ], fn ($v) => $v !== null),
        ]);
    }

    private function decline(HubLogin $login, AgentJob $job, ?SigningDevice $device): void
    {
        $login->update(['status' => 'rejected']);

        SignatureAudit::record(SignatureAudit::LOGIN_REJECTED, [
            'subject_user_id' => $login->user_id,
            'actor_user_id'   => $login->user_id,
            'device_id'       => $device?->id,
            'ip'              => $login->ip,
            'user_agent'      => $login->user_agent,
            'context'         => ['challenge' => $login->uuid, 'job' => $job->uuid, 'reason' => $job->reason],
        ]);
    }

    /** Retired, separated and rejected accounts never sign in again. */
    private function mayLogIn(int $userId): bool
    {
        $identity = Identity::forUser($userId);

        return $identity === null || $identity->isUsable();
    }

    private function expireIfDue(HubLogin $login): bool
    {
        if (in_array($login->status, ['pending', 'claimed', 'approved'], true) && $login->isExpired()) {
            $login->update(['status' => 'expired', 'link_token_hash' => null]);
        }

        return $login->status === 'expired';
    }

    /** Same /24 (IPv4) or /48 (IPv6): close enough for "the same building". */
    private static function sameNetwork(string $a, string $b): bool
    {
        $prefix = fn (string $ip): string => str_contains($ip, ':')
            ? implode(':', array_slice(explode(':', $ip), 0, 3))
            : implode('.', array_slice(explode('.', $ip), 0, 3));

        return $prefix($a) === $prefix($b);
    }
}
