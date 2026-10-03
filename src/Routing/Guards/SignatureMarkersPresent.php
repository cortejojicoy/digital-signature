<?php

namespace Kukux\DigitalSignature\Routing\Guards;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\PlacesSlotsInDocument;
use Kukux\DigitalSignature\Contracts\RoutingGuard;
use Kukux\DigitalSignature\Pdf\SlotDefinition;
use Kukux\DigitalSignature\Routing\RoutingResult;
use Kukux\DigitalSignature\Services\SignatoryRouter;
use Kukux\DigitalSignature\Support\HumanList;

/**
 * Every required slot has somewhere to go on this record's document.
 *
 * Two ways a template says where a signature goes, and this checks the one in
 * use:
 *
 *  - The view marks its signature spaces with `data-signature-slot` and the
 *    renderer records where they landed. Once a document uses markers, every
 *    required slot must be marked: a missing one means the view lost it, and
 *    signing at the sample coordinates would put that signature wherever the
 *    sample happened to have it.
 *  - The view marks nothing, and slots are placed in the designer or by their
 *    defaults. Then each required slot needs one of those.
 *
 * Without this check the session would open and stall at signing time on
 * "slot has no placement", which looks like the document is in progress when
 * nothing can happen.
 */
class SignatureMarkersPresent implements RoutingGuard
{
    public function __construct(protected SignatoryRouter $router)
    {
    }

    public function check(Model $record, PdfTemplate $template, array $context): ?RoutingResult
    {
        $required = array_values(array_filter(
            $template->slots(),
            fn (SlotDefinition $slot): bool => $slot->required,
        ));

        if ($required === []) {
            return null;
        }

        $marked = $template instanceof PlacesSlotsInDocument
            ? $template->documentPlacements($record)
            : [];

        if ($marked !== []) {
            $lost = array_filter($required, fn (SlotDefinition $slot): bool => ! isset($marked[$slot->key]));
        } else {
            $routes = $this->router->routeFor($record, $template->key());
            $lost = array_filter($required, fn (SlotDefinition $slot): bool => ($routes[$slot->key] ?? null)?->position === null);
        }

        if ($lost === []) {
            return null;
        }

        return RoutingResult::refused(RoutingResult::MARKERS_MISSING, [
            'slots' => HumanList::join(array_map(fn (SlotDefinition $slot): string => $slot->label, array_values($lost))),
        ]);
    }
}
