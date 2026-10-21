/**
 * The local half of document verification, against real signed PDFs.
 *
 * The fixtures are signed by the package's own FpdiDriver (see
 * tests/Fixtures/verification/generate.php), so these tests read the byte
 * layout TCPDF really writes. Each case is something a holder could hand the
 * verify page: the genuine file, a file signed by a CA we don't trust, a file
 * edited inside what was signed, and a file saved again after signing.
 *
 * Run with: npm run test:js
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
    findLastSignature,
    inspectPdf,
    parsePemCertificates,
    sha256Hex,
} from '../../resources/js/verify/pdfSignature.js';

const dir = join(dirname(fileURLToPath(import.meta.url)), '../Fixtures/verification');
const read = (name) => new Uint8Array(readFileSync(join(dir, name)));
const fixtures = JSON.parse(readFileSync(join(dir, 'hashes.json'), 'utf8'));

const trusted = parsePemCertificates(readFileSync(join(dir, 'ca.pem'), 'utf8'));
const stranger = parsePemCertificates(readFileSync(join(dir, 'other-ca.pem'), 'utf8'));

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

/** Append bytes the way an incremental save does: after %%EOF, untouched before. */
function appendUpdate(bytes) {
    const tail = new TextEncoder().encode('\n99 0 obj\n<< /Note (edited after signing) >>\nendobj\n%%EOF\n');
    const out = new Uint8Array(bytes.length + tail.length);
    out.set(bytes, 0);
    out.set(tail, bytes.length);

    return out;
}

console.log('pdfSignature');

await test('fingerprints the whole file with the hash the server stored', async () => {
    for (const name of ['base.pdf', 'v1.pdf', 'v2.pdf']) {
        assert.equal(await sha256Hex(read(name)), fixtures.sha256[name], name);
    }
});

await test('finds no signature in the unsigned base document', async () => {
    const result = await inspectPdf(read('base.pdf'), trusted);

    assert.equal(result.signature, null);
    assert.equal(result.signedRevisionSha256, null);
});

await test('verifies the last signer of a progressively signed file against the trusted CA', async () => {
    const result = await inspectPdf(read('v2.pdf'), trusted);

    assert.equal(result.signature.intact, true);
    assert.equal(result.signature.trusted, true);
    assert.equal(result.signature.signer, fixtures.signers['v2.pdf']);
    assert.equal(result.signature.coversWholeFile, true);
    assert.equal(result.signedRevisionSha256, result.sha256);
    assert.ok(result.signature.signedAt, 'signing time read from the signed attributes');
});

await test('reads each version\'s own signer', async () => {
    const result = await inspectPdf(read('v1.pdf'), trusted);

    assert.equal(result.signature.signer, fixtures.signers['v1.pdf']);
    assert.equal(result.signature.trusted, true);
});

await test('keeps "intact" and "trusted" apart for a CA we do not trust', async () => {
    const result = await inspectPdf(read('v2.pdf'), stranger);

    assert.equal(result.signature.intact, true);
    assert.equal(result.signature.trusted, false);
    assert.equal(result.signature.error, 'untrusted_chain');
});

await test('reports trust as unchecked, not failed, when no CA could be fetched', async () => {
    const result = await inspectPdf(read('v2.pdf'), []);

    assert.equal(result.signature.intact, true);
    assert.equal(result.signature.trusted, null);
});

await test('breaks when a signed byte is changed', async () => {
    const bytes = read('v2.pdf');
    const { byteRange } = findLastSignature(bytes);

    // A byte well inside the first signed segment, past the header.
    const at = Math.floor(byteRange[1] / 2);
    bytes[at] = bytes[at] === 0x20 ? 0x21 : 0x20;

    const result = await inspectPdf(bytes, trusted);

    assert.notEqual(result.sha256, fixtures.sha256['v2.pdf']);
    assert.equal(result.signature.intact, false);
    assert.equal(result.signature.error, 'signature_mismatch');
});

await test('recovers the signed copy from a file saved again after signing', async () => {
    const result = await inspectPdf(appendUpdate(read('v2.pdf')), trusted);

    assert.notEqual(result.sha256, fixtures.sha256['v2.pdf'], 'whole file no longer matches');
    assert.equal(result.signedRevisionSha256, fixtures.sha256['v2.pdf'], 'but its signed prefix does');
    assert.equal(result.signature.coversWholeFile, false);
    assert.equal(result.signature.intact, true);
});

await test('ignores a /ByteRange that is only text and points at no signature', async () => {
    const decoy = new TextEncoder().encode('%PDF-1.4\n(/ByteRange [0 10 20 5]) is just a string here\n%%EOF\n');

    assert.equal(findLastSignature(decoy), null);
});

await test('ignores a /ByteRange that runs past the end of the file', async () => {
    const decoy = new TextEncoder().encode('%PDF-1.4\n/ByteRange [0 12 14 999999] <00>\n%%EOF\n');

    assert.equal(findLastSignature(decoy), null);
});

if (failures > 0) {
    console.error(`\n${failures} failing`);
    process.exit(1);
}
