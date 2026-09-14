/**
 * CSS pixels ⇄ PDF points.
 *
 * This is the arithmetic that decides where a signature physically lands on a
 * legal document, and every input that could go wrong is one the browser
 * chooses: a non-Letter page, a zoom level, a page the browser laid out at
 * some size other than the one we asked for. So the cases below are all
 * "something other than the happy default" rather than 1:1 on US Letter.
 *
 * Run with: npm run test:js   (node only, no dependencies)
 */
import assert from 'node:assert/strict';
import { cssRectToPdfPoints, pdfPointsToCssRect } from '../../resources/js/utils/pdfCoords.js';

let failures = 0;

function test(name, fn) {
    try {
        fn();
        console.log(`  ✓ ${name}`);
    } catch (e) {
        failures++;
        console.error(`  ✗ ${name}`);
        console.error(`    ${e.message}`);
    }
}

const near = (actual, expected, tolerance, what) =>
    assert.ok(
        Math.abs(actual - expected) <= tolerance,
        `${what}: expected ~${expected}, got ${actual}`,
    );

// A4, not Letter — a page size whose points are not round numbers.
const A4 = { widthPt: 595.28, heightPt: 841.89 };

console.log('pdfCoords');

test('flips the y axis and subtracts the height', () => {
    // Rendered 1:1, so CSS pixels and points are the same unit here and the
    // only thing under test is the origin flip.
    const box  = { width: A4.widthPt, height: A4.heightPt };
    const rect = { x: 100, y: 200, width: 160, height: 50 };

    const pdf = cssRectToPdfPoints(rect, A4, box);

    assert.equal(pdf.x, 100);
    assert.equal(pdf.width, 160);
    assert.equal(pdf.height, 50);
    // Top edge 200px down the page ⇒ bottom edge is 841.89 − 200 − 50 up it.
    near(pdf.y, 841.89 - 200 - 50, 0.01, 'y');
});

test('scales down a page the browser rendered larger than 1:1', () => {
    // 150% zoom: the box measures half again as much as the page is wide.
    const box  = { width: A4.widthPt * 1.5, height: A4.heightPt * 1.5 };
    const rect = { x: 150, y: 300, width: 240, height: 75 };

    const pdf = cssRectToPdfPoints(rect, A4, box);

    near(pdf.x, 100, 0.01, 'x');
    near(pdf.width, 160, 0.01, 'width');
    near(pdf.height, 50, 0.01, 'height');
    near(pdf.y, 841.89 - 200 - 50, 0.01, 'y');
});

test('scales up a page the browser rendered smaller than 1:1', () => {
    // The case that motivated measuring instead of trusting the zoom: browser
    // page zoom at 80% shrinks the laid-out box without anyone telling us.
    const box  = { width: A4.widthPt * 0.8, height: A4.heightPt * 0.8 };
    const rect = { x: 80, y: 160, width: 128, height: 40 };

    const pdf = cssRectToPdfPoints(rect, A4, box);

    near(pdf.x, 100, 0.01, 'x');
    near(pdf.width, 160, 0.01, 'width');
    near(pdf.y, 841.89 - 200 - 50, 0.01, 'y');
});

test('handles a box whose axes scaled differently', () => {
    // Not something the viewer does on purpose, but if a container ever
    // stretches one axis the conversion must not silently use the other's
    // scale for both.
    const box  = { width: A4.widthPt * 2, height: A4.heightPt * 0.5 };
    const pdf  = cssRectToPdfPoints({ x: 200, y: 100, width: 320, height: 25 }, A4, box);

    near(pdf.x, 100, 0.01, 'x');
    near(pdf.width, 160, 0.01, 'width');
    near(pdf.height, 50, 0.01, 'height');
    near(pdf.y, 841.89 - 200 - 50, 0.01, 'y');
});

test('round-trips a frozen placement back to the same rectangle', () => {
    // The seeded box has to land where the administrator put the slot, or a
    // signatory who simply drops and commits moves the signature.
    const box       = { width: A4.widthPt * 1.25, height: A4.heightPt * 1.25 };
    const placement = { x: 100, y: 591.89, width: 160, height: 50 };

    const rect  = pdfPointsToCssRect(placement, A4, box);
    const again = cssRectToPdfPoints(rect, A4, box);

    near(again.x, placement.x, 0.01, 'x');
    near(again.y, placement.y, 0.01, 'y');
    near(again.width, placement.width, 0.01, 'width');
    near(again.height, placement.height, 0.01, 'height');
});

test('keeps a landscape page from borrowing the wrong axis', () => {
    const landscape = { widthPt: 841.89, heightPt: 595.28 };
    const box = { width: landscape.widthPt, height: landscape.heightPt };

    const pdf = cssRectToPdfPoints({ x: 10, y: 10, width: 100, height: 30 }, landscape, box);

    near(pdf.y, 595.28 - 10 - 30, 0.01, 'y');
    near(pdf.width, 100, 0.01, 'width');
});

if (failures > 0) {
    console.error(`\n${failures} failing`);
    process.exit(1);
}

console.log('\nall passing');
