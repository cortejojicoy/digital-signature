/**
 * The browser half of the device-proof contract.
 *
 * The message a device signs is rebuilt independently by
 * DeviceProofVerifier.php; if the two drift by one byte every proof fails.
 * These cases pin the format, and check that a real Web Crypto signature
 * over it verifies. tests/Fixtures/device-proof.json is a proof produced by
 * this same code, which the PHP suite verifies (DeviceProofVerifierTest).
 *
 * Regenerate the fixture with: node tests/js/deviceProof.test.mjs --write-fixture
 *
 * Run with: npm run test:js   (node only, no dependencies)
 */
import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
import { webcrypto } from 'node:crypto';
import {
    bytesToBase64,
    proofMessage,
    signMessage,
    spkiFingerprint,
} from '../../resources/js/utils/deviceProof.js';

const subtle = webcrypto.subtle;
let failures = 0;

async function test(name, fn) {
    try {
        await fn();
        console.log(`  ✓ ${name}`);
    } catch (e) {
        failures++;
        console.error(`  ✗ ${name}`);
        console.error(`    ${e.message}`);
    }
}

await test('builds the v1 pipe-joined message', () => {
    assert.equal(proofMessage('attest', 'n0nce', 42, 'ab12'), 'v1|attest|n0nce|42|ab12');
});

await test('stringifies the user id the same way PHP does', () => {
    assert.equal(proofMessage('attest', 'x', 7, ''), 'v1|attest|x|7|');
});

await test('fingerprints the SPKI as lowercase hex SHA-256', async () => {
    const pair = await subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify']);
    const fp = await spkiFingerprint(subtle, await subtle.exportKey('spki', pair.publicKey));
    assert.match(fp, /^[0-9a-f]{64}$/);
});

await test('produces a raw 64-byte P-256 signature that verifies', async () => {
    const pair = await subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify']);
    const message = proofMessage('attest', 'abc', 1, 'def');
    const b64 = await signMessage(subtle, pair.privateKey, message);
    const raw = Buffer.from(b64, 'base64');

    assert.equal(raw.length, 64);
    assert.ok(await subtle.verify(
        { name: 'ECDSA', hash: 'SHA-256' }, pair.publicKey, raw, new TextEncoder().encode(message),
    ));
});

await test('cannot export the private key', async () => {
    const pair = await subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify']);
    await assert.rejects(subtle.exportKey('pkcs8', pair.privateKey));
});

if (process.argv.includes('--write-fixture')) {
    const pair = await subtle.generateKey({ name: 'ECDSA', namedCurve: 'P-256' }, false, ['sign', 'verify']);
    const spki = await subtle.exportKey('spki', pair.publicKey);
    const fingerprint = await spkiFingerprint(subtle, spki);
    const nonce = 'fixture-nonce-Zm9vYmFy';
    const userId = 1;
    const message = proofMessage('attest', nonce, userId, fingerprint);

    writeFileSync(new URL('../Fixtures/device-proof.json', import.meta.url), JSON.stringify({
        public_key: bytesToBase64(spki),
        fingerprint,
        nonce,
        user_id: userId,
        message,
        signature: await signMessage(subtle, pair.privateKey, message),
        signature_format: 'raw',
    }, null, 4) + '\n');

    console.log('  wrote tests/Fixtures/device-proof.json');
}

if (failures > 0) {
    console.error(`\n${failures} failing`);
    process.exit(1);
}
console.log('\nall passing');
