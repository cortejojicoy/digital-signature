<?php

namespace Kukux\DigitalSignature\Signatories;

/**
 * Lifecycle of a single routed slot on a single record.
 *
 *  unassigned ──(person tagged)──▶ awaiting_registration
 *                                       │ (they register a signature)
 *                                       ▼
 *                                  awaiting_consent
 *                                       │ (grant, or they approve in person)
 *                                       ▼
 *                                     ready ──(stamped + PKCS#7)──▶ signed
 *                                       │
 *                                       └──(they decline)──▶ declined
 *
 * `blocked` is orthogonal to the rest: it means the slot is otherwise ready
 * but a lower-ordered required slot has not been signed yet, so a sequential
 * session will not accept it. It never replaces `unassigned` or
 * `awaiting_registration` — those name a problem someone has to fix, and
 * "waiting on an earlier signatory" would hide it.
 */
enum RouteState: string
{
    /** No person is tagged for this role on the record. */
    case Unassigned = 'unassigned';

    /** A person is tagged, but has never registered a signature image. */
    case AwaitingRegistration = 'awaiting_registration';

    /** Signature exists; waiting for the signatory to approve this document. */
    case AwaitingConsent = 'awaiting_consent';

    /** Everything is in place — this slot can be signed right now. */
    case Ready = 'ready';

    /** Waiting on an earlier slot in a sequential session. */
    case Blocked = 'blocked';

    /** Signed and embedded in the document. */
    case Signed = 'signed';

    /** The signatory refused. */
    case Declined = 'declined';

    /** Human-readable label for UI surfaces. */
    public function label(): string
    {
        return match ($this) {
            self::Unassigned           => 'No signatory assigned',
            self::AwaitingRegistration => 'Signatory has no registered signature',
            self::AwaitingConsent      => 'Awaiting signature',
            self::Ready                => 'Ready to sign',
            self::Blocked              => 'Waiting on an earlier signatory',
            self::Signed               => 'Signed',
            self::Declined             => 'Declined',
        };
    }

    /**
     * Filament/Tailwind colour name for badges.
     */
    public function color(): string
    {
        return match ($this) {
            self::Signed                                  => 'success',
            self::Ready                                   => 'info',
            self::AwaitingConsent, self::Blocked          => 'warning',
            self::Declined                                => 'danger',
            self::Unassigned, self::AwaitingRegistration  => 'gray',
        };
    }

    /**
     * True when the slot has reached a state that needs no further action.
     */
    public function isTerminal(): bool
    {
        return $this === self::Signed || $this === self::Declined;
    }

    /**
     * True when the slot is waiting on somebody or something.
     */
    public function isOutstanding(): bool
    {
        return ! $this->isTerminal();
    }
}
