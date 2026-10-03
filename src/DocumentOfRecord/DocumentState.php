<?php

namespace Kukux\DigitalSignature\DocumentOfRecord;

use Kukux\DigitalSignature\Models\SigningSession;

/**
 * Where a routed document stands, as its document of record shows it.
 */
enum DocumentState: string
{
    /** Every required signature is on it. */
    case Complete = 'complete';

    /** Open, and at least one signature is on it. */
    case InProgress = 'in_progress';

    /** Open, and nobody has signed yet: the frozen render. */
    case Pending = 'pending';

    /**
     * Cancelled or expired. Still shown: what was signed stays reviewable,
     * and pretending the document never existed would erase that.
     */
    case Withdrawn = 'withdrawn';

    public static function of(SigningSession $session, bool $hasSignatures): self
    {
        return match (true) {
            $session->status === SigningSession::STATUS_COMPLETE => self::Complete,
            in_array($session->status, [SigningSession::STATUS_CANCELLED, SigningSession::STATUS_EXPIRED], true) => self::Withdrawn,
            $hasSignatures => self::InProgress,
            default => self::Pending,
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Complete   => 'success',
            self::InProgress => 'warning',
            self::Pending    => 'info',
            self::Withdrawn  => 'gray',
        };
    }
}
