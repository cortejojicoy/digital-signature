<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Illuminate\Contracts\Session\Session;
use Kukux\DigitalSignature\Agent\AgentServer;

/**
 * "This browser": a random value kept in the session, stored only as its
 * hash on what the browser started (a guest pairing, a sign-in challenge).
 *
 * A value in the session rather than the session id itself, because signing
 * in regenerates the id (session fixation) while the data carries over: the
 * browser that started a pairing is still that browser after it signs in.
 */
final class BrowserBinding
{
    public const KEY = 'signature.hub.binding';

    public static function hash(Session $session): string
    {
        $value = $session->get(self::KEY);

        if (! is_string($value) || $value === '') {
            $value = AgentServer::token();
            $session->put(self::KEY, $value);
        }

        return hash('sha256', $value);
    }

    public static function matches(Session $session, ?string $hash): bool
    {
        $value = $session->get(self::KEY);

        return is_string($value) && $value !== '' && is_string($hash)
            && hash_equals($hash, hash('sha256', $value));
    }
}
