<?php

namespace Kukux\DigitalSignature\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * A template that can say where its slots are in one record's document,
 * rather than only where they are in the sample.
 *
 * When a signing session opens, these placements win over the designer's.
 * A slot left out falls back to the designer placement, then its default.
 */
interface PlacesSlotsInDocument
{
    /**
     * @return array<string, array{page: int, x: float, y: float, width: float, height: float}>
     *         slot key => placement, in PDF points with y from the bottom
     */
    public function documentPlacements(Model $record): array;
}
