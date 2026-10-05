/**
 * How a stamp divides the box a signatory placed.
 *
 * The format is COA Circular No. 2021-006, IV.C.13: the handwritten signature
 * on the left, and beside it the signatory's full name and the moment of
 * signing ("Digitally signed / by Juan DelaCruz / Date: … / …").
 *
 * The text block is a FIXED size. Resizing a placement scales the ink and
 * nothing else, so the name reads the same on every stamp in the document.
 *
 * This is the browser half of `DrawsSignatureStamp`, working in PDF points so
 * it is directly comparable with it. The one place the two legitimately
 * differ is text measurement: TCPDF knows exactly how wide Helvetica renders
 * and the browser only approximates it, so the text column may come out a
 * fraction of a point wider or narrower in the preview.
 */

const DEFAULT_RULES = {
    caption: { enabled: true, fontSize: 7, minFont: 4, lineHeight: 1.15, gap: 3 },
};

function captionRulesOf(rules) {
    return { ...DEFAULT_RULES.caption, ...(rules?.caption ?? {}) };
}

/**
 * @param {object} box       { width, height } in PDF points.
 * @param {string[]} lines   Caption lines, already chosen server-side.
 * @param {object} rules     The `stamp` block from the meta payload.
 * @param {number} [aspect]  Natural width/height of the signature image.
 * @returns {{image: {x,y,width,height}, caption: object}}
 */
export function layoutStamp(box, lines = [], rules = DEFAULT_RULES, aspect) {
    const captionRules = captionRulesOf(rules);

    const caption = layoutCaption(box, lines, captionRules);

    const gap      = caption.lines.length > 0 ? captionRules.gap : 0;
    const inkAreaW = Math.max(0, box.width - caption.width - gap);
    const ink      = fitWithin(aspect, inkAreaW, box.height);

    // Ink and text travel together, so the name stays beside the signature
    // it belongs to instead of drifting to the far edge as the box grows.
    const groupW = ink.w + gap + caption.width;
    const left   = Math.max(0, (box.width - groupW) / 2);

    return {
        image: {
            x: left,
            y: Math.max(0, (box.height - ink.h) / 2),
            width: ink.w,
            height: ink.h,
        },
        caption: {
            ...caption,
            x: left + ink.w + gap,
            y: Math.max(0, (box.height - caption.height) / 2),
            w: caption.width,
        },
    };
}

/**
 * The space the text block claims at its fixed size, in PDF points: the
 * width taken off the box (gap included) and the least height that holds it.
 *
 * What a resize handle needs to scale the ink alone — the box is always
 * `reserveWidth + ink width` wide and at least `minHeight` tall.
 */
export function captionReserve(lines = [], rules = DEFAULT_RULES) {
    const captionRules = captionRulesOf(rules);
    const block = measureBlock(cleanLines(lines, captionRules), captionRules.fontSize, captionRules);

    if (block.width === 0) return { reserveWidth: 0, minHeight: 0 };

    return { reserveWidth: block.width + captionRules.gap, minHeight: block.height };
}

/**
 * A box that holds the ink at `inkWidth` points wide next to the text block.
 */
export function stampBoxForInk(inkWidth, aspect, lines = [], rules = DEFAULT_RULES) {
    const { reserveWidth, minHeight } = captionReserve(lines, rules);
    const inkHeight = inkWidth / (aspect && aspect > 0 ? aspect : 3.2);

    return { width: inkWidth + reserveWidth, height: Math.max(inkHeight, minHeight) };
}

/**
 * A sensible box for a signature that has just been dropped.
 *
 * The text takes its fixed width first, so the ink lands at its own natural
 * shape rather than being squeezed into whatever the caption leaves.
 *
 * @param {number} aspect     Natural width/height of the signature image.
 * @param {number} maxWidth   Widest the box may be, in PDF points.
 * @param {object} rules      The `stamp` block from the meta payload.
 * @param {string[]} lines    The caption lines that will print beside it.
 */
export function defaultStampBox(aspect, maxWidth, rules = DEFAULT_RULES, lines = []) {
    const { reserveWidth } = captionReserve(lines, rules);

    const inkWidth = Math.max(24, Math.min(maxWidth * 0.2, 120, maxWidth - reserveWidth));
    const box = stampBoxForInk(inkWidth, aspect, lines, rules);

    return { width: Math.min(box.width, maxWidth), height: box.height };
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

function cleanLines(lines, rules) {
    if (!rules.enabled) return [];

    return (lines ?? []).map((l) => String(l).trim()).filter(Boolean);
}

/**
 * Fixed size, stepping down only when the box cannot hold the block next to
 * a usable sliver of ink. Lines are never truncated: the full name is the
 * point of the caption.
 */
function layoutCaption(box, lines, rules) {
    const candidates = cleanLines(lines, rules);

    if (candidates.length === 0) {
        return { lines: [], size: 0, height: 0, width: 0 };
    }

    const minSize  = Math.min(rules.fontSize, rules.minFont);
    const maxWidth = box.width - rules.gap - box.width * 0.25;

    for (let size = rules.fontSize; size >= minSize; size -= 0.25) {
        const block = measureBlock(candidates, size, rules);

        if (block.width <= maxWidth && block.height <= box.height) {
            return { lines: candidates, ...block };
        }
    }

    return { lines: candidates, ...measureBlock(candidates, minSize, rules) };
}

function measureBlock(lines, size, rules) {
    if (lines.length === 0) return { size, height: 0, width: 0 };

    return {
        size,
        height: lines.length * size * rules.lineHeight,
        width: Math.max(...lines.map((line) => measure(line, size))),
    };
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
        // half its point size per character, which is close enough for a
        // preview's layout.
        return text.length * size * 0.5;
    }

    context.font = `${size}px Helvetica, Arial, sans-serif`;

    return context.measureText(text).width;
}
