<?php

namespace Kukux\DigitalSignature\Contracts;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Routing\RoutingResult;

/**
 * One precondition for routing a document, checked against its record.
 *
 * Runs inside the routing transaction, after the record has been opened, so a
 * refusal rolls the record back with it. Guards are resolved from the
 * container: take whatever app service you need in the constructor.
 *
 *   final class ItineraryIsApproved implements RoutingGuard
 *   {
 *       public function check(Model $record, PdfTemplate $template, array $context): ?RoutingResult
 *       {
 *           return $record->itinerary_approved_at === null
 *               ? RoutingResult::refused('itinerary_not_approved')
 *               : null;
 *       }
 *   }
 */
interface RoutingGuard
{
    /**
     * @param  array<string, mixed>  $context  what the caller passed to DocumentRouter::route()
     * @return RoutingResult|null  null to pass; a refused result to stop
     */
    public function check(Model $record, PdfTemplate $template, array $context): ?RoutingResult;
}
