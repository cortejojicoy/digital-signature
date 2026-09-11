<?php

namespace Kukux\DigitalSignature\Signatories;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Pdf\SlotDefinition;
use Kukux\DigitalSignature\Models\Signature;

/**
 * One row of SignatoryRouter's answer: everything known about a single slot
 * on a single record, joined across the template declaration, the saved
 * placement, the tagged person, their signature library and any signing
 * session already in flight.
 *
 * Every UI surface and the signing pipeline read this same object, so the
 * "who fills this and can they?" question is answered in exactly one place.
 */
final readonly class SignatoryRoute
{
    public function __construct(
        public SlotDefinition $slot,

        /**
         * Resolved placement in PDF points — the persisted
         * `digital_pdf_template_slots` row if one exists, else the slot's
         * declared defaults, else null when neither is available.
         *
         * @var array{page:int,x:float,y:float,width:float,height:float}|null
         */
        public ?array $position,

        /** The tagged signatory, or null when the role is unfilled. */
        public ?Model $user,

        /** The signatory's active primary signature, if they have registered one. */
        public ?Signature $signature,

        /** The workflow row for this slot, when a signing session is open. */
        public ?SignatureRequest $request,

        public RouteState $state,
    ) {
    }

    public function key(): string
    {
        return $this->slot->key;
    }

    public function label(): string
    {
        return $this->slot->label;
    }

    public function role(): string
    {
        return $this->slot->role();
    }

    public function isRequired(): bool
    {
        return $this->slot->required;
    }

    public function signerName(): ?string
    {
        return $this->user?->getAttribute('name');
    }

    public function signerEmail(): ?string
    {
        return $this->user?->getAttribute('email');
    }

    /**
     * True when this slot can be signed right now by its assigned signatory.
     */
    public function isSignable(): bool
    {
        return $this->state === RouteState::Ready
            && $this->user !== null
            && $this->signature !== null
            && $this->position !== null;
    }

    /**
     * True when the session cannot complete until this slot is resolved.
     */
    public function blocksCompletion(): bool
    {
        return $this->isRequired() && $this->state !== RouteState::Signed;
    }

    /**
     * Why this slot isn't signable yet, phrased for an end user. Null when
     * it is signable or already signed.
     */
    public function blockerMessage(): ?string
    {
        return match ($this->state) {
            RouteState::Unassigned => sprintf(
                'No one is assigned as "%s" on this record.',
                $this->slot->label,
            ),
            RouteState::AwaitingRegistration => sprintf(
                '%s has not registered a signature yet.',
                $this->signerName() ?? 'The assigned signatory',
            ),
            RouteState::AwaitingConsent => sprintf(
                'Waiting for %s to sign.',
                $this->signerName() ?? 'the assigned signatory',
            ),
            RouteState::Blocked => 'An earlier signatory must sign first.',
            RouteState::Declined => sprintf(
                '%s declined to sign%s',
                $this->signerName() ?? 'The assigned signatory',
                $this->request?->declined_reason
                    ? ': '.$this->request->declined_reason
                    : '.',
            ),
            default => $this->position === null
                ? 'This slot has no saved placement — open the template designer and position it.'
                : null,
        };
    }

    /**
     * @return array<string, mixed> Serializable shape for JSON/Blade consumption.
     */
    public function toArray(): array
    {
        return [
            'slot'        => $this->slot->key,
            'label'       => $this->slot->label,
            'role'        => $this->role(),
            'order'       => $this->slot->order,
            'required'    => $this->isRequired(),
            'state'       => $this->state->value,
            'state_label' => $this->state->label(),
            'state_color' => $this->state->color(),
            'position'    => $this->position,
            'user'        => $this->user === null ? null : [
                'id'    => $this->user->getKey(),
                'name'  => $this->signerName(),
                'email' => $this->signerEmail(),
            ],
            'signature'   => $this->signature === null ? null : [
                'uuid'       => $this->signature->uuid,
                'previewUrl' => $this->signature->getTemporaryImageUrl(60),
            ],
            'signed_at'   => $this->request?->responded_at?->toIso8601String(),
            'blocker'     => $this->blockerMessage(),
        ];
    }
}
