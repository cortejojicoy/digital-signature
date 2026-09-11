<?php

namespace Kukux\DigitalSignature\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Kukux\DigitalSignature\Models\SignatureDelegation;
use Kukux\DigitalSignature\Models\SignatureRequest;

/**
 * Tells a signatory that their signature was just applied without them.
 *
 * This notification is the practical half of the consent model: a standing
 * delegation is only safe if the grantor can see it being used and revoke it.
 * Sending it is deliberately not optional per-signature — the only switch is
 * the deployment-wide `signature.auto_affix.notify`.
 */
class SignatureAutoAffixedNotification extends Notification
{
    use Queueable;

    public function __construct(
        public readonly SignatureRequest $request,
        public readonly SignatureDelegation $delegation,
    ) {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return config('signature.auto_affix.notification_channels', ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $session = $this->request->session;
        $document = $session?->signable;
        $title = $document && method_exists($document, 'getSignableTitle')
            ? $document->getSignableTitle()
            : ($session?->template_key ?? 'a document');

        $expiry = $this->delegation->expires_at?->toDayDateTimeString();

        return (new MailMessage())
            ->subject('Your signature was applied to '.$title)
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line(sprintf(
                'Your registered signature was applied to **%s** as "%s".',
                $title,
                $this->request->role,
            ))
            ->line('This happened automatically under the signing authorisation you granted'
                .($expiry ? ', which expires on '.$expiry.'.' : '.'))
            ->line('If you did not expect this, revoke the authorisation from your account '
                .'immediately — revoking takes effect at once and blocks any further automatic signing.')
            ->line('Reference: '.$this->request->uuid);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event'        => 'signature.auto_affixed',
            'request_uuid' => $this->request->uuid,
            'session_uuid' => $this->request->session?->uuid,
            'template'     => $this->request->session?->template_key,
            'role'         => $this->request->role,
            'delegation'   => $this->delegation->uuid,
            'signed_at'    => $this->request->responded_at?->toIso8601String(),
        ];
    }
}
