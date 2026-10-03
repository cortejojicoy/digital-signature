<?php

namespace Kukux\DigitalSignature\Routing\Guards;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\RoutingGuard;
use Kukux\DigitalSignature\Routing\RoutingResult;
use Kukux\DigitalSignature\Services\SignatoryRouter;
use Kukux\DigitalSignature\Signatories\RouteState;

/**
 * Everyone named can actually sign: they have a login and a registered
 * signature.
 *
 * Checked before the session opens because a session that stalls on "the
 * Dean has no signature" looks in progress while nothing can happen. Only
 * those two states refuse; a slot waiting on an earlier signatory is exactly
 * what routing is about to set up.
 */
class SignatoriesReady implements RoutingGuard
{
    public function __construct(protected SignatoryRouter $router)
    {
    }

    public function check(Model $record, PdfTemplate $template, array $context): ?RoutingResult
    {
        $blockers = [];

        foreach ($this->router->routeFor($record, $template->key()) as $route) {
            if ($route->isRequired()
                && in_array($route->state, [RouteState::Unassigned, RouteState::AwaitingRegistration], true)) {
                $blockers[] = $route->blockerMessage() ?? $route->state->label();
            }
        }

        if ($blockers === []) {
            return null;
        }

        return RoutingResult::refused(RoutingResult::NOT_READY, [
            'blockers' => implode(' ', $blockers),
        ]);
    }
}
