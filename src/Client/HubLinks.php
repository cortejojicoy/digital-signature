<?php

namespace Kukux\DigitalSignature\Client;

/**
 * Where "Change it at the hub" links point: the person's Profile at the hub
 * (`hub.url` + `hub.profile_path`), with `return_to` so the hub can send them
 * back to the page they came from.
 */
final class HubLinks
{
    public static function profile(?string $returnTo = null): string
    {
        $url = rtrim((string) config('signature.hub.url'), '/')
            .'/'.ltrim((string) config('signature.hub.profile_path', '/'), '/');

        if ($returnTo === null || $returnTo === '') {
            return $url;
        }

        return $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['return_to' => $returnTo]);
    }
}
