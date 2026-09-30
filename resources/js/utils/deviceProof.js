/**
 * Pure helpers shared by the device key and its tests.
 *
 * Kept free of IndexedDB and DOM access so Node can import it: the test suite
 * checks that this builds byte-for-byte the message DeviceProofVerifier.php
 * verifies.
 */

export const PROOF_VERSION = 'v1';

/**
 * `v1|<purpose>|<nonce>|<user_id>|<payload_hash>` — the only thing a device
 * key ever signs. Every field comes from the server or from the key itself.
 */
export function proofMessage(purpose, nonce, userId, payloadHash) {
    return [PROOF_VERSION, purpose, nonce, String(userId), payloadHash].join('|');
}

export function bytesToBase64(buffer) {
    const bytes = new Uint8Array(buffer);
    let binary = '';
    for (let i = 0; i < bytes.length; i++) binary += String.fromCharCode(bytes[i]);
    return btoa(binary);
}

export function bytesToHex(buffer) {
    return Array.from(new Uint8Array(buffer))
        .map((b) => b.toString(16).padStart(2, '0'))
        .join('');
}

/** SHA-256 of the SPKI DER, hex — the server's key_fingerprint. */
export async function spkiFingerprint(subtle, spki) {
    return bytesToHex(await subtle.digest('SHA-256', spki));
}

/**
 * Signs a proof message. Web Crypto returns raw r‖s (64 bytes) for P-256;
 * the server converts it to DER (see EcdsaSignature.php).
 */
export async function signMessage(subtle, privateKey, message) {
    const signature = await subtle.sign(
        { name: 'ECDSA', hash: 'SHA-256' },
        privateKey,
        new TextEncoder().encode(message),
    );
    return bytesToBase64(signature);
}
