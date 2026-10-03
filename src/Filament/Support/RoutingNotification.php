<?php

namespace Kukux\DigitalSignature\Filament\Support;

use Filament\Notifications\Notification;
use Kukux\DigitalSignature\Routing\RoutingResult;

/**
 * Says what routing did, the same way on every surface: a success when it
 * routed (or was already routed), a warning naming what to fix when it
 * refused.
 */
final class RoutingNotification
{
    public static function send(RoutingResult $result, ?string $extra = null): void
    {
        $body = trim($result->body().' '.($extra ?? ''));

        $notification = Notification::make()
            ->title($result->title())
            ->body($body !== '' ? $body : null);

        $result->ok()
            ? $notification->success()->send()
            : $notification->warning()->send();
    }
}
