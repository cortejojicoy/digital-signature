<?php

namespace Kukux\DigitalSignature\Security;

use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Models\Signature;

/**
 * Detects the screenshot/copy-paste forgery pattern:
 * the same image hash submitted by a different user than the one who originally stored it.
 *
 * Inspired by LibreSign's hex signature deduplication approach (Pkcs12Handler.php).
 */
class DuplicateSignatureGuard
{
    /**
     * Throws ForgedSignatureException if the given image hash already exists
     * under a different user in a non-revoked/non-failed state.
     */
    public function check(string $imageHash, int $userId): void
    {
        $conflict = Signature::where('image_hash', $imageHash)
            ->where('user_id', '!=', $userId)
            ->whereIn('status', ['pending', 'signed'])
            ->exists();

        if ($conflict) {
            throw new ForgedSignatureException(
                'The submitted signature image matches an existing signature from another user. '
                . 'Submitting another person\'s signature image is not permitted.'
            );
        }
    }
}
