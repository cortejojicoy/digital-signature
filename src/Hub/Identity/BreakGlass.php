<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use InvalidArgumentException;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Break-glass sign-in (plan 1.7, D10): for when the agent, or a release, has
 * locked every admin out. Email + password + TOTP, from allowlisted IPs only,
 * for at most two configured super-admins, into the admin panel only.
 *
 *   'break_glass' => [
 *       'enabled' => true,
 *       'users'   => ['ict.head@uplb.edu.ph' => 'JBSWY3DPEHPK3PXP'],  // base32 TOTP secret
 *       'ips'     => ['10.0.5.0/24'],
 *   ]
 *
 * Every use is audited (`login.break_glass`) and logged at `alert`, so the
 * log channel's alerting tells someone at once. The session it opens is
 * marked (HubAccess::BREAK_GLASS): it never signs and never serves OIDC.
 */
class BreakGlass
{
    public const MAX_USERS = 2;

    private const FAILED = 'Those details are not valid for break-glass sign-in.';

    public function enabled(): bool
    {
        return (bool) config('signature.hub.break_glass.enabled', false) && $this->users() !== [];
    }

    /** IP-restricted by design: with no allowlist, nobody may use it. */
    public function allows(?string $ip): bool
    {
        $ips = array_values(array_filter((array) config('signature.hub.break_glass.ips', [])));

        return $this->enabled() && $ip !== null && $ips !== [] && IpUtils::checkIp($ip, $ips);
    }

    /**
     * @throws IdentityException
     */
    public function attempt(Session $session, string $email, string $password, string $code, ?string $ip, ?string $userAgent = null): Authenticatable
    {
        if (! $this->allows($ip)) {
            Log::warning('signature.hub: break-glass sign-in refused (disabled or IP not allowed).', ['ip' => $ip]);

            throw new IdentityException('Break-glass sign-in is not available from here.', 'not_allowed');
        }

        $limiter = 'signature-hub-break-glass:'.$ip;

        if (RateLimiter::tooManyAttempts($limiter, 5)) {
            throw new IdentityException('Too many attempts. Wait a minute and try again.', 'rate_limited');
        }

        RateLimiter::hit($limiter, 60);

        $email = strtolower(trim($email));
        $secret = $this->users()[$email] ?? null;
        $guard = Auth::guard();
        $provider = $guard->getProvider();
        $user = $secret === null ? null : $provider->retrieveByCredentials(['email' => $email]);

        try {
            $step = $secret === null ? null : Totp::verify($secret, $code);
        } catch (InvalidArgumentException) {
            $step = null;
        }

        if ($user === null || $step === null || ! $provider->validateCredentials($user, ['password' => $password])
            || ! HubAccess::isSuperAdmin($user)
            // A code works once, even inside its 30-second window.
            || ! Cache::add("signature:hub:break-glass:{$email}:{$step}", true, Totp::STEP * 4)) {
            Log::warning('signature.hub: break-glass sign-in failed.', ['ip' => $ip, 'email' => $email]);

            throw new IdentityException(self::FAILED, 'invalid');
        }

        RateLimiter::clear($limiter);

        $guard->login($user);
        $session->regenerate();
        $session->put(HubAccess::BREAK_GLASS, true);

        SignatureAudit::record(SignatureAudit::BREAK_GLASS_USED, [
            'subject_user_id' => $user->getAuthIdentifier(),
            'actor_user_id'   => $user->getAuthIdentifier(),
            'ip'              => $ip,
            'user_agent'      => $userAgent,
            'context'         => ['email' => $email],
        ]);

        Log::alert('signature.hub: BREAK-GLASS sign-in used.', ['user_id' => $user->getAuthIdentifier(), 'email' => $email, 'ip' => $ip]);

        return $user;
    }

    /**
     * Configured accounts, lower-cased emails, at most two (D10).
     *
     * @return array<string, string>
     */
    public function users(): array
    {
        $users = [];

        foreach ((array) config('signature.hub.break_glass.users', []) as $email => $secret) {
            if (is_string($email) && is_string($secret) && $secret !== '') {
                $users[strtolower(trim($email))] = $secret;
            }
        }

        return array_slice($users, 0, self::MAX_USERS, true);
    }
}
