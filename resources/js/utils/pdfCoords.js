/**
 * The one conversion between what a signatory sees and what gets stamped.
 *
 * Two coordinate systems meet here and disagree about almost everything:
 *
 *   CSS pixels   — origin top-left, y grows downward, size depends on the
 *                  zoom level and on whatever the browser actually laid the
 *                  page out at.
 *   PDF points   — origin bottom-left, y grows upward, 72 to the inch, fixed
 *                  by the document.
 *
 * Both functions take the page's size in points and the *measured* box of the
 * element it was rendered into. Measured, not assumed: page zoom, a
 * constraining container or a devicePixelRatio mismatch all change the
 * rendered size without changing the document, and a signature committed at a
 * scale nobody was looking at is a mis-stamped legal document rather than a
 * cosmetic bug.
 */

/**
 * @param  {{x:number,y:number,width:number,height:number}} rect  CSS pixels, relative to the page box.
 * @param  {{widthPt:number,heightPt:number}} page
 * @param  {{width:number,height:number}} box  Measured size of the rendered page.
 * @return {{x:number,y:number,width:number,height:number}} PDF points, y from the page bottom.
 */
export function cssRectToPdfPoints(rect, page, box) {
    const perPxX = page.widthPt  / box.width;
    const perPxY = page.heightPt / box.height;

    const width  = rect.width  * perPxX;
    const height = rect.height * perPxY;

    return {
        x:      round2(rect.x * perPxX),
        // The rectangle's CSS top edge is its PDF *top* edge; PDF y names the
        // bottom one, so the height has to come off as well as the flip.
        y:      round2(page.heightPt - (rect.y * perPxY) - height),
        width:  round2(width),
        height: round2(height),
    };
}

/**
 * The inverse — used to open a box on the placement frozen at request time.
 *
 * @param  {{x:number,y:number,width:number,height:number}} placement  PDF points.
 * @param  {{widthPt:number,heightPt:number}} page
 * @param  {{width:number,height:number}} box
 * @return {{x:number,y:number,width:number,height:number}} CSS pixels.
 */
export function pdfPointsToCssRect(placement, page, box) {
    const pxPerPtX = box.width  / page.widthPt;
    const pxPerPtY = box.height / page.heightPt;

    return {
        x:      placement.x * pxPerPtX,
        y:      (page.heightPt - placement.y - placement.height) * pxPerPtY,
        width:  placement.width  * pxPerPtX,
        height: placement.height * pxPerPtY,
    };
}

function round2(n) {
    return Math.round(n * 100) / 100;
}
