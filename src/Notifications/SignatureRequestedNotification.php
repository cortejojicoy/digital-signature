<?php

namespace Kukux\DigitalSignature\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Kukux\DigitalSignature\Models\SignatureRequest;

/**
 * Tells a signatory a document is waiting for them.
 *
 * Not wired up by default — the package fires SignatureRequested and leaves
 * delivery to the host, because who gets told and how is an app decision.
 * Register it in a listener, or set signature.sessions.notify_on_request.
 */
class SignatureRequestedNotification extends Notification
{
    use Queueable;

    public function __construct(public readonly SignatureRequest $request)
    {
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return config('signature.sessions.notification_channels', ['mail']);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $session = $this->request->session;
        $document = $session?->signable;
        $title = $document && method_exists($document, 'getSignableTitle')
            ? $document->getSignableTitle()
            : ($session?->template_key ?? 'a document');

        return (new MailMessage())
            ->subject('Your signature is requested on '.$title)
            ->greeting('Hello '.($notifiable->name ?? '').',')
            ->line(sprintf('You are listed as "%s" on **%s**.', $this->request->role, $title))
            ->line('It is ready for your signature.')
            ->line('Reference: '.$this->request->uuid);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event'        => 'signature.requested',
            'request_uuid' => $this->request->uuid,
            'session_uuid' => $this->request->session?->uuid,
            'template'     => $this->request->session?->template_key,
            'role'         => $this->request->role,
        ];
    }
}
