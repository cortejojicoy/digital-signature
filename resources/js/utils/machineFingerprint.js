/**
 * Stable browser / machine fingerprint utility.
 *
 * Collects signals that are consistent for a given browser profile on a given
 * machine, hashes them with SHA-256, and caches the result in localStorage so
 * the fingerprint stays the same across page loads on the same device.
 *
 * The hash is sent to the server alongside every signature submission.  The
 * server embeds it (along with the userId and a timestamp) as HMAC-signed PNG
 * tEXt metadata, binding each signature image to the machine that created it.
 *
 * Security note: the fingerprint itself is not a secret — the security comes
 * from the server-side HMAC, which proves the metadata was written by this
 * server and not forged.
 */

const STORAGE_KEY = 'sig_device_fp';

async function collect() {
    const signals = [
        navigator.userAgent       ?? '',
        navigator.language        ?? '',
        navigator.platform        ?? '',
        String(navigator.hardwareConcurrency ?? ''),
        String(screen.width)  + 'x' + String(screen.height),
        String(screen.colorDepth),
        String(new Date().getTimezoneOffset()),
    ];

    // Canvas fingerprint — text rendering differs by OS, GPU, and font engine
    try {
        const c   = document.createElement('canvas');
        const ctx = c.getContext('2d');
        ctx.font         = '11px monospace';
        ctx.fillStyle    = '#1a1a1a';
        ctx.fillText('sig\u2665device', 0, 12);
        // Take only the tail of the data URI to avoid sending the full image
        signals.push(c.toDataURL('image/png').slice(-40));
    } catch (_) { /* canvas blocked by privacy settings */ }

    // WebGL renderer — varies by GPU driver
    try {
        const gl = document.createElement('canvas').getContext('webgl');
        if (gl) {
            const info = gl.getExtension('WEBGL_debug_renderer_info');
            if (info) {
                signals.push(gl.getParameter(info.UNMASKED_RENDERER_WEBGL) ?? '');
                signals.push(gl.getParameter(info.UNMASKED_VENDOR_WEBGL)   ?? '');
            }
        }
    } catch (_) { /* WebGL unavailable */ }

    const raw = signals.join('|');
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(raw));
    return Array.from(new Uint8Array(buf))
        .map(b => b.toString(16).padStart(2, '0'))
        .join('');
}

/** Cached in-memory reference for the current page load. */
let _promise = null;

/**
 * Returns a promise that resolves to the device fingerprint hex string.
 * The result is cached in localStorage for subsequent page loads and in
 * memory for subsequent calls within the same page.
 */
export function getFingerprint() {
    if (_promise) return _promise;

    _promise = (async () => {
        const stored = localStorage.getItem(STORAGE_KEY);
        if (stored) return stored;

        const fp = await collect();
        try { localStorage.setItem(STORAGE_KEY, fp); } catch (_) { /* storage full */ }
        return fp;
    })();

    return _promise;
}
