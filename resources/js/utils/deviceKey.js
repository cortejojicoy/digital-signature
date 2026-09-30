/**
 * This browser's signing-device key — the package's `~/.ssh/id_ecdsa`.
 *
 * A P-256 key pair generated with `extractable: false` and kept in IndexedDB.
 * Script on this origin can *use* the private key but can never read its
 * bytes, so the key cannot be copied to another machine; the server holds
 * only the public half.
 *
 * Supersedes machineFingerprint.js as the device identifier: a fingerprint is
 * a value the browser asserts, whereas this is a key it has to prove it holds.
 */

import { bytesToBase64, signMessage, spkiFingerprint } from './deviceProof.js';

const DB_NAME = 'kukux-signature';
const STORE = 'device-keys';
const KEY_ID = 'default';

export function isDeviceKeySupported() {
    return typeof indexedDB !== 'undefined' && !!globalThis.crypto?.subtle;
}

function withStore(mode, fn) {
    return new Promise((resolve, reject) => {
        const open = indexedDB.open(DB_NAME, 1);
        open.onupgradeneeded = () => open.result.createObjectStore(STORE);
        open.onerror = () => reject(open.error);
        open.onsuccess = () => {
            const db = open.result;
            const tx = db.transaction(STORE, mode);
            const request = fn(tx.objectStore(STORE));
            tx.oncomplete = () => { db.close(); resolve(request?.result); };
            tx.onerror = () => { db.close(); reject(tx.error); };
        };
    });
}

let pairPromise = null;

/** The stored key pair, generated on first use. */
export function getDeviceKeyPair() {
    if (pairPromise) return pairPromise;

    pairPromise = (async () => {
        const stored = await withStore('readonly', (store) => store.get(KEY_ID));
        if (stored?.privateKey) return stored;

        const pair = await crypto.subtle.generateKey(
            { name: 'ECDSA', namedCurve: 'P-256' },
            false,               // private key is non-extractable
            ['sign', 'verify'],
        );
        await withStore('readwrite', (store) => store.put(pair, KEY_ID));

        // Ask the browser not to evict the key under storage pressure.
        navigator.storage?.persist?.().catch(() => {});

        return pair;
    })().catch((error) => {
        pairPromise = null;
        throw error;
    });

    return pairPromise;
}

export async function getPublicKeySpki() {
    const { publicKey } = await getDeviceKeyPair();
    return crypto.subtle.exportKey('spki', publicKey);
}

export async function getKeyFingerprint() {
    return spkiFingerprint(crypto.subtle, await getPublicKeySpki());
}

export async function getPublicKeyBase64() {
    return bytesToBase64(await getPublicKeySpki());
}

export async function signWithDeviceKey(message) {
    const { privateKey } = await getDeviceKeyPair();
    return signMessage(crypto.subtle, privateKey, message);
}

/** Forget this browser's key. The next use generates a new one — a new device. */
export async function resetDeviceKey() {
    pairPromise = null;
    await withStore('readwrite', (store) => store.delete(KEY_ID));
}

/** What the page can tell the server about the device, for its label only. */
export function deviceHints() {
    const uaData = navigator.userAgentData;
    const hints = { touch: (navigator.maxTouchPoints ?? 0) > 1 };
    if (uaData) {
        hints.mobile = !!uaData.mobile;
        if (uaData.platform) hints.platform = uaData.platform;
    }
    return hints;
}
