<?php

namespace Kukux\DigitalSignature\Enums;

/**
 * The group a DeviceType belongs to: drives icons, the grouped select on the
 * pairing confirm, and policy. Derived from the type, never stored.
 */
enum DeviceCategory: string
{
    case Computer = 'computer';
    case Tablet = 'tablet';
    case Phone = 'phone';
    case Virtual = 'virtual';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Computer => 'Computers',
            self::Tablet   => 'Tablets',
            self::Phone    => 'Phones',
            self::Virtual  => 'Virtual machines',
            self::Other    => 'Other',
        };
    }
}
