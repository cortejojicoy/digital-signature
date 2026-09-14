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
        position: 'bottom', widthRatio: 0.42, minBoxWidth: 110,
    },
    qr: { enabled: true, minSize: 26, maxSize: 48, gap: 2 },
};

export const CAPTION_POSITIONS = ['bottom', 'top', 'left', 'right'];

/**
 * @param {object} box       { width, height } in PDF points.
 * @param {string[]} lines   Caption lines, already chosen server-side.
 * @param {object} rules     The `stamp` block from the meta payload.
 * @param {string} [position] Which side the caption takes, overriding the
 *                            configured default for this one placement.
 * @returns {{image: {x,y,width,height}, qr: {x,y,size}|null, caption: object}}
 */
export function layoutStamp(box, lines = [], rules = DEFAULT_RULES, position, aspect) {
    const captionRules = { ...DEFAULT_RULES.caption, ...(rules?.caption ?? {}) };
    const qrRules      = { ...DEFAULT_RULES.qr,      ...(rules?.qr      ?? {}) };

    const side = CAPTION_POSITIONS.includes(position)
        ? position
        : (CAPTION_POSITIONS.includes(captionRules.position) ? captionRules.position : 'bottom');

    const vertical = side === 'left' || side === 'right';

    const caption = vertical
        ? layoutCaptionColumn(box, lines, captionRules)
        : layoutCaption(box, lines, captionRules);

    const band = vertical ? caption.width : caption.height;

    // The slab left once the caption has taken its side.
    const contentX = side === 'left' ? band : 0;
    const contentW = vertical ? box.width - band : box.width;
    const contentH = vertical ? box.height : box.height - band;

    const qr  = qrSize({ width: contentW, height: contentH }, qrRules);
    const gap = qr > 0 ? qrRules.gap : 0;

    const inkAreaW = contentW - (qr > 0 ? qr + gap : 0);
    const ink = fitWithin(aspect, inkAreaW, contentH);

    // Ink and caption travel together. Centring the pair — rather than the ink
    // alone — is what keeps the provenance tucked under the signature at every
    // box size, instead of drifting to the bottom edge as the box grows.
    const groupHeight = vertical ? ink.h : ink.h + caption.height;
    const groupTop = Math.max(0, (box.height - groupHeight) / 2);

    const inkY = vertical ? Math.max(0, (box.height - ink.h) / 2)
        : side === 'top' ? groupTop + caption.height
        : groupTop;

    const inkX = contentX + Math.max(0, (inkAreaW - ink.w) / 2);

    const captionBox = side === 'bottom' ? { x: contentX, y: inkY + ink.h, w: inkAreaW }
        : side === 'top'                 ? { x: contentX, y: groupTop,     w: inkAreaW }
        : side === 'left'                ? { x: 0,        y: 0,            w: band }
        : /* right */                      { x: box.width - band, y: 0,    w: band };

    // A column caption centres against the ink it sits beside.
    if (vertical && caption.lines.length > 0) {
        captionBox.y = Math.max(0, (box.height - caption.height) / 2);
    }

    return {
        position: side,
        image: { x: inkX, y: inkY, width: ink.w, height: ink.h },
        qr: qr > 0
            ? {
                x: contentX + contentW - qr,
                // Level with the ink, so the two read as one mark.
                y: inkY + Math.max(0, (ink.h - qr) / 2),
                size: qr,
            }
            : null,
        caption: { ...caption, ...captionBox },
    };
}

/**
 * The largest rectangle of the given proportions that fits the space.
 *
 * With no aspect to honour, the space is used as-is.
 */
function fitWithin(aspect, maxWidth, maxHeight) {
    if (!aspect || aspect <= 0) return { w: maxWidth, h: maxHeight };

    const w = Math.min(maxWidth, maxHeight * aspect);

    return { w, h: w / aspect };
}

/**
 * The caption stacked down a column beside the signature.
 *
 * Sized against the band's WIDTH rather than the box's — a column is always
 * narrow, and a line that fits the whole box says nothing about whether it
 * fits the strip it is going in.
 */
function layoutCaptionColumn(box, lines, rules) {
    const empty = { lines: [], size: 0, height: 0, width: 0, align: rules.align };

    const candidates = (lines ?? []).map((l) => String(l).trim()).filter(Boolean);

    if (candidates.length === 0 || !rules.enabled) return empty;
    if (box.width < rules.minBoxWidth) return empty;

    const band = box.width * Math.max(0.1, Math.min(0.9, rules.widthRatio));

    for (let size = rules.maxFont; size >= rules.minFont; size -= 0.25) {
        const lineHeight = size * rules.lineHeight;

        if (candidates.length * lineHeight > box.height) continue;

        const widest = Math.max(...candidates.map((line) => measure(line, size)));

        if (widest <= band) {
            return {
                lines: candidates,
                size,
                height: candidates.length * lineHeight,
                width: band,
                align: rules.align,
            };
        }
    }

    const lineHeight = rules.minFont * rules.lineHeight;
    const fitting = Math.floor(box.height / lineHeight);

    if (fitting < 1) return empty;

    const chosen = candidates.slice(0, fitting).map((line) => truncate(line, band, rules.minFont));

    return {
        lines: chosen,
        size: rules.minFont,
        height: chosen.length * lineHeight,
        width: band,
        align: rules.align,
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

    let width = inkWidth + (qr > 0 ? qr + qrRules.gap : 0);

    // A caption beside the ink takes width instead of height, so give it its
    // share back — otherwise choosing "left" immediately squeezes the ink.
    const side = captionRules.position;
    if (captionRules.enabled && (side === 'left' || side === 'right')) {
        const ratio = Math.max(0.1, Math.min(0.9, captionRules.widthRatio));
        width = Math.max(width / (1 - ratio), captionRules.minBoxWidth);
    }

    return { width: Math.min(width, maxWidth), height };
}

function qrSize(box, rules) {
    if (!rules.enabled) return 0;

    // Never more than the box's own height, nor more than a third of its
    // width — past that the signature stops being the main mark.
    const size = Math.min(box.height, box.width / 3, rules.maxSize);

    return size >= rules.minSize ? size : 0;
}

function layoutCaption(box, lines, rules) {
    const empty = { lines: [], size: 0, height: 0, width: 0, align: rules.align };

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
            return { lines: chosen, size, height: chosen.length * lineHeight, width: box.width, align: rules.align };
        }
    }

    const lineHeight = rules.minFont * rules.lineHeight;
    const fitting = Math.floor(available / lineHeight);

    if (fitting < 1) return empty;

    const chosen = candidates.slice(0, fitting).map((line) => truncate(line, box.width, rules.minFont));

    return { lines: chosen, size: rules.minFont, height: chosen.length * lineHeight, width: box.width, align: rules.align };
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
