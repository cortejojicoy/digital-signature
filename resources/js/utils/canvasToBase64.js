/**
 * Export the signature canvas as a base64-encoded PNG with a transparent
 * background.
 *
 * PNG natively supports an alpha channel, so leaving the offscreen canvas
 * unfilled produces a transparent background. Downstream consumers (FPDI /
 * TCPDF PDF signers, browser previews, signature thumbnails) all handle
 * transparency correctly, and the rendered signature blends into whatever
 * surface it lands on instead of carrying a white rectangle around.
 *
 * Note: we still copy to an offscreen canvas rather than calling
 * `canvas.toDataURL()` directly so future encoding tweaks (cropping to
 * the drawn bounds, downsampling, format options) have a single place
 * to land.
 */
export function canvasToBase64(canvas) {
    const offscreen = document.createElement('canvas');
    offscreen.width  = canvas.width;
    offscreen.height = canvas.height;

    const ctx = offscreen.getContext('2d');
    ctx.drawImage(canvas, 0, 0);

    return offscreen.toDataURL('image/png');
}
