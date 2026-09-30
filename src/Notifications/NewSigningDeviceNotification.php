<?php

namespace Kukux\DigitalSignature\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Kukux\DigitalSignature\Models\SigningDevice;

/**
 * "A new device can now sign as you" — the equivalent of the mail a code host
 * sends when an SSH key is added.
 *
 * It is the main defence against a stolen session: whoever holds it can make
 * their own browser a registered device, but the owner hears about it and
 * can revoke it. Not sent for a user's first device, which is the ordinary
 * case of starting to use the feature, not a change to it.
 */
class NewSigningDeviceNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly SigningDevice $device) {}

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return config('signature.devices.notification_channels', ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())
            ->subject('A new device can sign with your signature')
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line(sprintf(
                '**%s** was registered as a signing device on your account.',
                $this->device->displayName(),
            ))
            ->line('Registered '.$this->device->created_at?->toDayDateTimeString()
                .($this->device->registered_ip ? ' from '.$this->device->registered_ip : '').'.')
            ->line('Key fingerprint: '.$this->device->shortFingerprint())
            ->line('If this was not you, revoke the device from your signing devices immediately '
                .'and change your password.');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'device_uuid' => $this->device->uuid,
            'label'       => $this->device->displayName(),
            'fingerprint' => $this->device->shortFingerprint(),
        ];
    }
}
