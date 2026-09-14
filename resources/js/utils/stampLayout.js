/**
 * How a stamp divides the box a signatory placed.
 *
 * The PDF writer carves the same box three ways — ink, a verification QR on
 * the right, a caption band along the bottom — and until this existed the
 * placement UI drew only the signature. A signatory lining a box up against a
 * form was therefore aligning something other than what would print.
 *
 * This is the browser half of `DrawsSignatureStamp`, working in PDF points so
 * it is directly comparable with it. The band arithmetic is identical. The one
 * place the two legitimately differ is text measurement: TCPDF knows exactly
 * how wide Helvetica renders and the browser only approximates it, so a line
 * that truncates in the preview may survive in the PDF or the reverse. That
 * affects an ellipsis, never where the ink lands.
 */

const DEFAULT_RULES = {
    caption: {
        enabled: true, minBoxHeight: 28, heightRatio: 0.38,
        maxFont: 6, minFont: 4, lineHeight: 1.06, align: 'C',
    },
    qr: { enabled: true, minSize: 26, maxSize: 48, gap: 2 },
};

/**
 * @param {object} box       { width, height } in PDF points.
 * @param {string[]} lines   Caption lines, already chosen server-side.
 * @param {object} rules     The `stamp` block from the meta payload.
 * @returns {{image: {width, height}, qr: number, caption: {lines, size, height, align}}}
 */
export function layoutStamp(box, lines = [], rules = DEFAULT_RULES) {
    const captionRules = { ...DEFAULT_RULES.caption, ...(rules?.caption ?? {}) };
    const qrRules      = { ...DEFAULT_RULES.qr,      ...(rules?.qr      ?? {}) };

    const caption = layoutCaption(box, lines, captionRules);
    const qr      = qrSize(box, qrRules);

    return {
        image: {
            width:  box.width - (qr > 0 ? qr + qrRules.gap : 0),
            height: box.height - caption.height,
        },
        qr,
        caption,
    };
}

/**
 * A sensible box for a signature that has just been dropped.
 *
 * Sizing it to the ink's aspect alone was wrong once the stamp gained a
 * caption and a QR: those take their share afterwards, leaving the signature
 * squashed into what remains. So the allowance is added first and the ink ends
 * up at the shape it actually is.
 *
 * @param {number} aspect     Natural width/height of the signature image.
 * @param {number} maxWidth   Widest the box may be, in PDF points.
 * @param {object} rules      The `stamp` block from the meta payload.
 */
export function defaultStampBox(aspect, maxWidth, rules = DEFAULT_RULES) {
    const captionRules = { ...DEFAULT_RULES.caption, ...(rules?.caption ?? {}) };
    const qrRules      = { ...DEFAULT_RULES.qr,      ...(rules?.qr      ?? {}) };

    const inkWidth  = Math.min(maxWidth * 0.3, 220);
    const inkHeight = inkWidth / (aspect && aspect > 0 ? aspect : 3.2);

    // Grow by whatever the caption will claim, so the ink keeps its own height
    // rather than surrendering a third of it.
    const ratio  = captionRules.enabled
        ? Math.max(0.1, Math.min(0.9, captionRules.heightRatio))
        : 0;
    const height = Math.max(inkHeight / (1 - ratio), captionRules.minBoxHeight);

    // Then by the QR, measured against the box we now know the height of.
    const qr = qrSize({ width: inkWidth, height }, qrRules);

    return {
        width:  Math.min(inkWidth + (qr > 0 ? qr + qrRules.gap : 0), maxWidth),
        height,
    };
}

function qrSize(box, rules) {
    if (!rules.enabled) return 0;

    // Never more than the box's own height, nor more than a third of its
    // width — past that the signature stops being the main mark.
    const size = Math.min(box.height, box.width / 3, rules.maxSize);

    return size >= rules.minSize ? size : 0;
}

function layoutCaption(box, lines, rules) {
    const empty = { lines: [], size: 0, height: 0, align: rules.align };

    const candidates = (lines ?? []).map((l) => String(l).trim()).filter(Boolean);

    if (candidates.length === 0 || !rules.enabled) return empty;
    if (box.height < rules.minBoxHeight) return empty;

    const available = box.height * Math.max(0.1, Math.min(0.9, rules.heightRatio));

    for (let size = rules.maxFont; size >= rules.minFont; size -= 0.25) {
        const lineHeight = size * rules.lineHeight;
        const fitting = Math.floor(available / lineHeight);

        if (fitting < 1) continue;

        const chosen = candidates.slice(0, fitting);
        const widest = Math.max(...chosen.map((line) => measure(line, size)));

        if (widest <= box.width) {
            return { lines: chosen, size, height: chosen.length * lineHeight, align: rules.align };
        }
    }

    const lineHeight = rules.minFont * rules.lineHeight;
    const fitting = Math.floor(available / lineHeight);

    if (fitting < 1) return empty;

    const chosen = candidates.slice(0, fitting).map((line) => truncate(line, box.width, rules.minFont));

    return { lines: chosen, size: rules.minFont, height: chosen.length * lineHeight, align: rules.align };
}

// ── Text measurement ────────────────────────────────────────────────────────
//
// One cached 2D context rather than one per call: this runs on every frame of
// a resize drag, and allocating a canvas per frame is how a smooth drag turns
// into a stuttering one.

let context = null;

function measure(text, size) {
    if (context === null && typeof document !== 'undefined') {
        context = document.createElement('canvas').getContext('2d');
    }

    if (!context) {
        // No DOM (a test, a server render). Helvetica averages a little under
        // half its point size per character, which is close enough to decide a
        // preview's font step.
        return text.length * size * 0.5;
    }

    context.font = `${size}px Helvetica, Arial, sans-serif`;

    return context.measureText(text).width;
}

function truncate(line, width, size) {
    if (measure(line, size) <= width) return line;

    let out = line;
    while (out.length > 0 && measure(`${out}…`, size) > width) {
        out = out.slice(0, -1);
    }

    return out === '' ? '' : `${out}…`;
}
