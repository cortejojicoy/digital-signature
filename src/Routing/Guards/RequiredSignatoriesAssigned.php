<?php

namespace Kukux\DigitalSignature\Routing\Guards;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\RoutingGuard;
use Kukux\DigitalSignature\Routing\RoutingResult;
use Kukux\DigitalSignature\Services\SignatoryRouter;
use Kukux\DigitalSignature\Signatories\RouteState;
use Kukux\DigitalSignature\Support\HumanList;

/**
 * Every required slot names somebody.
 *
 * Driven by the template's own `required` slots and the router, so a model
 * never has to keep a second list of its roles in step with the template.
 * A slot that names someone without a login is not "missing": somebody is
 * set, and SignatoriesReady explains what's wrong with them instead.
 */
class RequiredSignatoriesAssigned implements RoutingGuard
{
    public function __construct(protected SignatoryRouter $router)
    {
    }

    public function check(Model $record, PdfTemplate $template, array $context): ?RoutingResult
    {
        $missing = [];

        foreach ($this->router->routeFor($record, $template->key()) as $route) {
            if ($route->isRequired()
                && $route->state === RouteState::Unassigned
                && ! $route->isTaggedWithoutLogin()) {
                $missing[] = $route->label();
            }
        }

        if ($missing === []) {
            return null;
        }

        return RoutingResult::refused(RoutingResult::MISSING_SIGNATORIES, [
            'roles' => HumanList::join($missing),
        ]);
    }
}
