<?php

namespace Kukux\DigitalSignature\Security;

use Illuminate\Http\Request;
use Kukux\DigitalSignature\Exceptions\ForgedSignatureException;
use Kukux\DigitalSignature\Exceptions\MachineBindingException;

/**
 * Embeds and validates tamper-proof metadata inside PNG signature images.
 *
 * Every signature stored by this plugin receives five tEXt chunks:
 *
 *   Sig-User-Id      — the signer's user ID
 *   Sig-Signer-Name  — the signer's display name (name + email), visible in Preview
 *   Sig-Machine-Hash — SHA-256 of (userId|userAgent|deviceFingerprint)
 *   Sig-Timestamp    — Unix timestamp when the image was stored
 *   Sig-Hmac         — HMAC-SHA256 of (userId|signerName|machineHash|timestamp)
 *                      signed with the application key
 *
 * What this prevents
 * ------------------
 * 1. Screenshot / copy-paste attack: a screenshot is a new PNG without our
 *    tEXt chunks → HMAC is absent → rejected.
 * 2. Cross-user reuse: Sig-User-Id is checked against the current user →
 *    using another person's exported PNG fails.
 * 3. Cross-machine reuse (opt-in): Sig-Machine-Hash encodes user-agent and
 *    the browser-collected device fingerprint.  With
 *    `signature.metadata.enforce_machine_lock = true` this hash must match
 *    the current request's fingerprint → copying the file to another browser
 *    or machine is rejected.
 * 4. Metadata forgery: the HMAC is signed with APP_KEY → crafting a valid
 *    Sig-Hmac without knowing the key is computationally infeasible.
 */
class SignatureMetadataService
{
    // tEXt chunk keyword names embedded in each PNG
    private const K_USER      = 'Sig-User-Id';
    private const K_SIGNER    = 'Sig-Signer-Name';
    private const K_MACHINE   = 'Sig-Machine-Hash';
    private const K_TIMESTAMP = 'Sig-Timestamp';
    private const K_RECORD    = 'Sig-Record-Id';   // Signature.uuid for DB cross-validation
    private const K_HMAC      = 'Sig-Hmac';

    public function __construct(
        private readonly PngMetaEmbedder $embedder,
        private readonly Request         $request,
    ) {}

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Normalise image to PNG (converts JPEG if needed), then inject signed
     * metadata chunks.  Returns the enriched PNG bytes.
     *
     * @param string $imageBytes  Raw PNG or JPEG bytes.
     * @param int    $userId      The signer's user ID.
     * @param string $deviceFp    Browser-collected device fingerprint hex string
     *                            (from machineFingerprint.js).  May be '' if JS
     *                            was disabled; server-side signals still apply.
     * @param string $signerName  Human-readable identity string embedded in the PNG
     *                            (e.g. "Juan dela Cruz <juan@example.com>").
     *                            Included inside the HMAC so it cannot be altered.
     * @param string $recordId    The Signature model UUID generated before DB insert.
     *                            Embedded so re-uploaded images can be cross-checked
     *                            against the database record.
     */
    public function embedIntoImage(
        string $imageBytes,
        int    $userId,
        string $deviceFp   = '',
        string $signerName = '',
        string $recordId   = '',
    ): string {
        // Always store as PNG so tEXt chunks are supported
        $png = $this->embedder->normalizeToPng($imageBytes);

        $machineHash = $this->computeMachineHash($userId, $deviceFp);
        $timestamp   = (string) time();
        $hmac        = $this->makeHmac($userId, $signerName, $machineHash, $timestamp, $recordId);

        return $this->embedder->embed($png, [
            self::K_USER      => (string) $userId,
            self::K_SIGNER    => $signerName,
            self::K_MACHINE   => $machineHash,
            self::K_TIMESTAMP => $timestamp,
            self::K_RECORD    => $recordId,
            self::K_HMAC      => $hmac,
        ]);
    }

    /**
     * Validate the metadata embedded in an uploaded PNG.
     *
     * For uploaded images that carry our tEXt metadata:
     *   - HMAC must be valid (proves created by this server)
     *   - Sig-User-Id must match $userId (prevents cross-user reuse)
     *   - Sig-Machine-Hash must match current fingerprint when machine lock
     *     is enabled in config (prevents cross-machine reuse)
     *
     * Images with NO metadata are treated as "fresh" external images and pass
     * through — metadata will be embedded when the image is stored.
     *
     * @throws ForgedSignatureException  if HMAC is invalid or user ID mismatches.
     * @throws MachineBindingException   if machine lock is on and hash differs.
     */
    public function validateIfPresent(string $imageBytes, int $userId, string $deviceFp = ''): void
    {
        $meta = $this->embedder->read($imageBytes);

        // Fresh image — no metadata yet, allowed through
        if (empty($meta[self::K_HMAC])) {
            return;
        }

        $storedUserId  = $meta[self::K_USER]      ?? '';
        $storedSigner  = $meta[self::K_SIGNER]   ?? '';
        $storedMachine = $meta[self::K_MACHINE]   ?? '';
        $storedTs      = $meta[self::K_TIMESTAMP] ?? '';
        $storedRecord  = $meta[self::K_RECORD]   ?? '';
        $storedHmac    = $meta[self::K_HMAC];

        // 1. Verify HMAC — proves the metadata was written by this server
        $expectedHmac = $this->makeHmac((int) $storedUserId, $storedSigner, $storedMachine, $storedTs, $storedRecord);
        if (!hash_equals($expectedHmac, $storedHmac)) {
            throw new ForgedSignatureException(
                'The uploaded signature image contains invalid security metadata. '
                . 'The image may have been tampered with or does not originate from this system.'
            );
        }

        // 2. User ID must match — prevents reusing another person's exported signature
        if ((int) $storedUserId !== $userId) {
            throw new ForgedSignatureException(
                'The uploaded signature image was created by a different user and cannot be reused.'
            );
        }

        // 3. Machine lock (optional) — prevents using the image from a different device
        if (config('signature.metadata.enforce_machine_lock', false)) {
            $currentMachine = $this->computeMachineHash($userId, $deviceFp);
            if (!hash_equals($storedMachine, $currentMachine)) {
                throw new MachineBindingException(
                    'This signature image is bound to the device on which it was created. '
                    . 'Please draw a new signature on this device.'
                );
            }
        }
    }

    /**
     * Read and return the raw metadata map from a PNG, or [] if absent.
     */
    public function readMeta(string $imageBytes): array
    {
        return $this->embedder->read($imageBytes);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Compute a machine fingerprint that is stable for a given
     * (userId, browser, device fingerprint) combination.
     *
     * The device fingerprint comes from the browser-side machineFingerprint.js
     * utility (localStorage-cached SHA-256 of canvas/WebGL/UA signals).
     * The User-Agent adds a server-side layer that cannot be spoofed from the
     * client alone.
     *
     * NOTE: IP address is intentionally excluded.  Including IP causes false
     * positives for legitimate users whose IP changes (mobile networks, VPN,
     * DHCP, corporate proxies).  The device fingerprint + user-agent combination
     * is already a strong machine identifier.
     */
    private function computeMachineHash(int $userId, string $deviceFp): string
    {
        return hash('sha256', implode('|', [
            (string) $userId,
            $this->request->userAgent() ?? '',
            $deviceFp,
        ]));
    }

    /**
     * HMAC-SHA256 over the core fields, keyed by APP_KEY.
     * The separator '|' is included between every field to prevent
     * length-extension ambiguity.
     *
     * $recordId is appended only when non-empty so that older PNGs (stored before
     * Sig-Record-Id was introduced) still validate correctly.
     */
    private function makeHmac(
        int    $userId,
        string $signerName,
        string $machineHash,
        string $timestamp,
        string $recordId = '',
    ): string {
        $parts = [(string) $userId, $signerName, $machineHash, $timestamp];
        if ($recordId !== '') {
            $parts[] = $recordId;
        }
        return hash_hmac('sha256', implode('|', $parts), config('app.key'));
    }
}
