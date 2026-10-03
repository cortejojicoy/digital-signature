<?php

namespace Kukux\DigitalSignature\Support;

/**
 * "A", "A and B", "A, B and C": how routing messages name several people or
 * slots at once.
 */
final class HumanList
{
    /** @param  list<string>  $items */
    public static function join(array $items, string $and = 'and'): string
    {
        $items = array_values(array_filter($items, fn ($item): bool => is_string($item) && $item !== ''));

        if (count($items) <= 1) {
            return $items[0] ?? '';
        }

        return implode(', ', array_slice($items, 0, -1)).' '.$and.' '.end($items);
    }
}
