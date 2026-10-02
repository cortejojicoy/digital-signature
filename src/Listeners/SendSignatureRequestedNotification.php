<?php

namespace Kukux\DigitalSignature\Listeners;

use Kukux\DigitalSignature\Events\SignatoryTurnReached;
use Kukux\DigitalSignature\Notifications\SignatureRequestedNotification;

/**
 * Tells a signatory their signature is needed, when it's their turn.
 * Channels come from `signature.sessions.notification_channels`; an empty
 * list turns these notifications off.
 */
class SendSignatureRequestedNotification
{
    public function handle(SignatoryTurnReached $event): void
    {
        if (config('signature.sessions.notification_channels', ['mail']) === []) {
            return;
        }

        $user = $event->request->user;

        // A user model without Notifiable can't be told; signing still works,
        // and the request still shows in their launcher.
        if ($user === null || ! method_exists($user, 'notify')) {
            return;
        }

        $user->notify(new SignatureRequestedNotification($event->request));
    }
}
