/**
 * Read and check the last digital signature in a PDF, entirely on the
 * holder's computer.
 *
 * The verify page (and Kukux Sign Agent) fingerprint a file and ask the server
 * whether it is a version the system issued. That answer is only as good as
 * the fingerprint, so this module does the parts that need the file itself:
 *
 *   - sha256 of the whole file, the lookup key;
 *   - the signed revision: the bytes up to the end of the last signature's
 *     /ByteRange. A file that was edited and saved after signing (an
 *     incremental update) still starts with exactly the bytes that were
 *     signed, so hashing this prefix finds the version the signer signed
 *     even though the whole-file hash no longer matches anything;
 *   - the PKCS#7 itself: does it cover these bytes, and does its certificate
 *     chain end at the CA this system trusts.
 *
 * Progressive signing (FPDI rebuilds the file on every pass) leaves one
 * signature per file, the last signer's. Earlier signatories are proven by
 * the record, not by this module, which is why the page always asks both.
 *
 * Pure: no DOM and no network, so the same code runs in the browser and in
 * Node (tests/js/pdfSignature.test.mjs).
 */
import * as asn1js from 'asn1js';
import * as pkijs from 'pkijs';

const BYTE_RANGE = /\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/g;

/** OID of the CMS signingTime signed attribute. */
const SIGNING_TIME = '1.2.840.113549.1.9.5';

/** OID of the X.520 commonName attribute. */
const COMMON_NAME = '2.5.4.3';

/**
 * Latin-1 view of the bytes: one char per byte, so string offsets are byte
 * offsets. Built in chunks; String.fromCharCode(...bytes) overflows the call
 * stack on a multi-megabyte file.
 */
function latin1(bytes) {
    let out = '';
    const chunk = 0x8000;

    for (let i = 0; i < bytes.length; i += chunk) {
        out += String.fromCharCode.apply(null, bytes.subarray(i, i + chunk));
    }

    return out;
}

function hexToBytes(hex) {
    const clean = hex.replace(/[^0-9a-fA-F]/g, '');
    const out = new Uint8Array(clean.length >> 1);

    for (let i = 0; i < out.length; i++) {
        out[i] = parseInt(clean.substr(i * 2, 2), 16);
    }

    return out;
}

export function toHex(buffer) {
    return Array.from(new Uint8Array(buffer), (b) => b.toString(16).padStart(2, '0')).join('');
}

export async function sha256Hex(bytes, subtle = globalThis.crypto.subtle) {
    return toHex(await subtle.digest('SHA-256', bytes));
}

/**
 * The last signature's location in the file, or null when there is none.
 *
 * "Last" by position: an incremental update appends, so the signature added
 * most recently is the one furthest into the file. Ranges that don't make
 * sense for this file (outside it, overlapping, a gap that isn't a hex
 * string) are skipped, so a /ByteRange that merely appears inside some text
 * can't be mistaken for a signature.
 *
 * @param {Uint8Array} bytes
 * @returns {{ byteRange: number[], revisionEnd: number, contents: Uint8Array, coversWholeFile: boolean } | null}
 */
export function findLastSignature(bytes) {
    const text = latin1(bytes);
    let found = null;

    for (const match of text.matchAll(BYTE_RANGE)) {
        const [a, b, c, d] = match.slice(1, 5).map(Number);

        if (a !== 0 || b <= 0 || c <= b || d < 0 || c + d > bytes.length) {
            continue;
        }

        const gap = text.slice(b, c);

        if (!/^<[0-9a-fA-F\s]*>$/.test(gap)) {
            continue;
        }

        if (found === null || c + d > found.revisionEnd) {
            found = {
                byteRange: [a, b, c, d],
                revisionEnd: c + d,
                contents: hexToBytes(gap.slice(1, -1)),
            };
        }
    }

    if (found === null) {
        return null;
    }

    return { ...found, coversWholeFile: found.revisionEnd === bytes.length };
}

/** The bytes a signature's /ByteRange covers, joined: what was actually signed. */
export function signedBytes(bytes, byteRange) {
    const [a, b, c, d] = byteRange;
    const out = new Uint8Array(b + d);

    out.set(bytes.subarray(a, a + b), 0);
    out.set(bytes.subarray(c, c + d), b);

    return out;
}

export function parsePemCertificates(pem) {
    const blocks = String(pem).match(/-----BEGIN CERTIFICATE-----[\s\S]+?-----END CERTIFICATE-----/g) ?? [];

    return blocks.map((block) => {
        const b64 = block.replace(/-----[A-Z ]+-----|\s+/g, '');
        const der = Uint8Array.from(atob(b64), (ch) => ch.charCodeAt(0));

        return pkijs.Certificate.fromBER(der);
    });
}

function commonName(certificate) {
    const cn = certificate?.subject?.typesAndValues?.find((tv) => tv.type === COMMON_NAME);

    return cn ? String(cn.value.valueBlock.value) : null;
}

function signingTime(signerInfo) {
    const attribute = signerInfo?.signedAttrs?.attributes?.find((a) => a.type === SIGNING_TIME);
    const value = attribute?.values?.[0];

    return value?.toDate ? value.toDate().toISOString() : null;
}

/**
 * Check one PKCS#7 / CMS blob against the bytes it should cover.
 *
 * intact:  the signature verifies and its messageDigest is these bytes' hash.
 * trusted: the signer's chain ends at one of trustedCerts. null when no
 *          trusted CA was given (the page couldn't fetch one): "not checked",
 *          which is not the same as "untrusted".
 *
 * @returns {Promise<{ intact: boolean, trusted: boolean|null, signer: string|null, signedAt: string|null, error: string|null }>}
 */
export async function verifyCms(cmsDer, data, trustedCerts = []) {
    let signedData;

    try {
        // The /Contents placeholder is zero-padded past the end of the DER,
        // so read the first element and ignore what follows.
        const asn = asn1js.fromBER(cmsDer);

        if (asn.offset === -1) {
            throw new Error('not DER');
        }

        const info = new pkijs.ContentInfo({ schema: asn.result });
        signedData = new pkijs.SignedData({ schema: info.content });
    } catch {
        return { intact: false, trusted: null, signer: null, signedAt: null, error: 'unreadable' };
    }

    const signerInfo = signedData.signerInfos?.[0];
    const base = { signedAt: signingTime(signerInfo) };

    // First the maths alone, so a broken signature is reported as broken and
    // not as "untrusted" just because the chain check ran first.
    let intact = false;
    let signerCertificate = null;

    try {
        const result = await signedData.verify({ signer: 0, data, checkChain: false, extendedMode: true });
        intact = result.signatureVerified === true;
        signerCertificate = result.signerCertificate ?? null;
    } catch (error) {
        signerCertificate = error?.signerCertificate ?? null;
    }

    const signer = commonName(signerCertificate ?? signedData.certificates?.[0]);

    if (!intact) {
        return { ...base, intact: false, trusted: null, signer, error: 'signature_mismatch' };
    }

    if (trustedCerts.length === 0) {
        return { ...base, intact: true, trusted: null, signer, error: null };
    }

    try {
        const result = await signedData.verify({
            signer: 0,
            data,
            checkChain: true,
            trustedCerts,
            extendedMode: true,
        });

        const trusted = result.signatureVerified === true && result.signerCertificateVerified === true;

        return { ...base, intact: true, trusted, signer, error: trusted ? null : 'untrusted_chain' };
    } catch {
        return { ...base, intact: true, trusted: false, signer, error: 'untrusted_chain' };
    }
}

/**
 * Everything the page needs from a file: its fingerprints and what its last
 * signature says about itself.
 *
 * @param {Uint8Array} bytes
 * @param {object[]} trustedCerts  pkijs.Certificate, from parsePemCertificates()
 */
export async function inspectPdf(bytes, trustedCerts = [], subtle = globalThis.crypto.subtle) {
    const sha256 = await sha256Hex(bytes, subtle);
    const signature = findLastSignature(bytes);

    if (signature === null) {
        return { sha256, signedRevisionSha256: null, signature: null };
    }

    const signedRevisionSha256 = signature.coversWholeFile
        ? sha256
        : await sha256Hex(bytes.subarray(0, signature.revisionEnd), subtle);

    const check = await verifyCms(signature.contents, signedBytes(bytes, signature.byteRange), trustedCerts);

    return {
        sha256,
        signedRevisionSha256,
        signature: { ...check, coversWholeFile: signature.coversWholeFile },
    };
}
