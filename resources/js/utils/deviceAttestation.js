/**
 * Proves to the server that this browser holds its device key.
 *
 * On page load, and again before each proof expires, it fetches a single-use
 * challenge, signs it with the non-extractable key, and posts the answer.
 * The server then records this device on any signature created or used in
 * this session. See DeviceRegistry.php.
 */

import { proofMessage } from './deviceProof.js';
import {
    deviceHints,
    getKeyFingerprint,
    getPublicKeyBase64,
    isDeviceKeySupported,
    resetDeviceKey,
    signWithDeviceKey,
} from './deviceKey.js';

let started = false;

function postJson(url, body) {
    return fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            'Content-Type': 'application/json',
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]')?.content ?? '',
        },
        body: JSON.stringify(body),
    });
}

/**
 * @param {{ challengeUrl: string, attestUrl: string }} config
 */
export function startDeviceAttestation({ challengeUrl, attestUrl }) {
    // wire:navigate keeps the page alive between panel pages; one loop is enough.
    if (started || !challengeUrl || !attestUrl || !isDeviceKeySupported()) return;
    started = true;

    let expiresAt = 0;
    let timer = null;
    let inFlight = null;

    async function attest(allowReset = true) {
        const challengeResponse = await postJson(challengeUrl, {});
        if (!challengeResponse.ok) return; // signed out, throttled, or disabled

        const { nonce, user_id: userId } = await challengeResponse.json();
        const fingerprint = await getKeyFingerprint();

        const response = await postJson(attestUrl, {
            public_key: await getPublicKeyBase64(),
            signature: await signWithDeviceKey(proofMessage('attest', nonce, userId, fingerprint)),
            signature_format: 'raw',
            nonce,
            hints: deviceHints(),
        });

        // This key was revoked. Start over with a new one: it will register
        // as a new device, and its owner will be told.
        if (response.status === 409 && allowReset) {
            await resetDeviceKey();
            return attest(false);
        }

        if (!response.ok) return;

        const result = await response.json();
        expiresAt = Date.now() + result.expires_in * 1000;
        schedule(result.expires_in);

        window.dispatchEvent(new CustomEvent('kukux-signature:device', { detail: result }));
    }

    function run() {
        inFlight ??= attest().catch(() => {}).finally(() => { inFlight = null; });
        return inFlight;
    }

    function schedule(ttlSeconds) {
        clearTimeout(timer);
        // Well before expiry, so a signature submitted at the edge still
        // lands on a fresh proof.
        timer = setTimeout(run, Math.max(30, ttlSeconds * 0.6) * 1000);
    }

    // A backgrounded tab's timers get throttled; catch up on return.
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'visible' && Date.now() > expiresAt - 60_000) run();
    });

    run();
}
