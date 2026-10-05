/**
 * How a stamp divides its box, in the browser.
 *
 * This is the preview half of a layout the PDF writer also performs, so the
 * cases below are the ones where the two must agree: where the ink and the
 * name go, and that only the ink scales. If these drift, a signatory
 * aligns a box against a form and something else prints into it.
 *
 * Run with: npm run test:js   (node only, no dependencies)
 */
import assert from 'node:assert/strict';
import { layoutStamp, defaultStampBox, captionReserve, stampBoxForInk } from '../../resources/js/utils/stampLayout.js';

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
    assert.ok(Math.abs(actual - expected) <= tolerance,
        `${what}: expected ~${expected}, got ${actual}`);

const LINES = ['Digitally signed', 'by Juan DelaCruz', 'Date: 2020.05.21', "19:37:33 +08'00'"];

console.log('stampLayout');

test('puts the ink on the left and the text beside it, inside the box', () => {
    const l = layoutStamp({ width: 220, height: 60 }, LINES, undefined, 3);

    assert.ok(l.caption.lines.length === 4, 'expected all four lines');
    assert.ok(l.caption.x >= l.image.x + l.image.width, 'text should sit right of the ink');
    assert.ok(l.caption.x + l.caption.w <= 220 + 0.01, 'text overflows the width');
    assert.ok(l.caption.y + l.caption.height <= 60 + 0.01, 'text overflows the height');
    assert.ok(l.image.y + l.image.height <= 60 + 0.01, 'ink overflows the height');
    assert.equal('qr' in l, false, 'there is no QR any more');
});

test('keeps the text at one size whatever the box', () => {
    const small = layoutStamp({ width: 200, height: 50 }, LINES, undefined, 3);
    const large = layoutStamp({ width: 500, height: 200 }, LINES, undefined, 3);

    assert.equal(small.caption.size, 7);
    assert.equal(large.caption.size, 7);
    near(large.caption.w, small.caption.w, 0.001, 'text width');
    assert.ok(large.image.width > small.image.width, 'only the ink should grow');
});

test('steps the text down only for a box too small to hold it', () => {
    const l = layoutStamp({ width: 90, height: 22 }, LINES, undefined, 3);

    assert.ok(l.caption.size < 7 && l.caption.size >= 4, `size was ${l.caption.size}`);
    assert.deepEqual(l.caption.lines, LINES, 'lines must never be dropped or truncated');
});

test('keeps the text tucked beside the ink at every width', () => {
    for (const width of [200, 300, 500]) {
        const l = layoutStamp({ width, height: 80 }, LINES, undefined, 3);

        near(l.caption.x, l.image.x + l.image.width + 3, 0.01, `gap at width ${width}`);
    }
});

test('keeps the signature at its own proportions', () => {
    for (const aspect of [1, 2.5, 6]) {
        const l = layoutStamp({ width: 300, height: 160 }, LINES, undefined, aspect);

        near(l.image.width / l.image.height, aspect, 0.01, `aspect ${aspect}`);
    }
});

test('honours rules sent by the server over its own defaults', () => {
    const l = layoutStamp({ width: 220, height: 70 }, LINES, { caption: { enabled: false } });

    assert.deepEqual(l.caption.lines, []);
    near(l.image.width, 220, 0.01, 'image width');
    near(l.image.height, 70, 0.01, 'image height');
});

test('reserves the fixed text width for ink-only resizing', () => {
    const { reserveWidth, minHeight } = captionReserve(LINES);

    assert.ok(reserveWidth > 0 && minHeight > 0);

    // Whatever ink width is asked for, the box is the ink plus the same
    // reserve, and the ink fills its share at its own shape.
    for (const ink of [60, 120, 240]) {
        const box = stampBoxForInk(ink, 3, LINES);
        near(box.width - reserveWidth, ink, 0.01, `ink ${ink}`);

        const l = layoutStamp(box, LINES, undefined, 3);
        near(l.image.width, ink, 0.5, `laid-out ink at ${ink}`);
    }
});

test('sizes a dropped box so the ink keeps its own shape', () => {
    const box = defaultStampBox(4, 600, undefined, LINES);
    const l = layoutStamp(box, LINES, undefined, 4);

    near(l.image.width / l.image.height, 4, 0.01, 'ink aspect');
    assert.ok(l.caption.size === 7, 'a dropped box should hold the text at full size');
});

test('never proposes a box wider than the page allows', () => {
    const box = defaultStampBox(4, 100, undefined, LINES);

    assert.ok(box.width <= 100, `box was ${box.width} on a 100pt page`);
});

test('fills the space when there is no aspect to honour', () => {
    const l = layoutStamp({ width: 300, height: 100 }, [], { caption: { enabled: false } });

    near(l.image.width, 300, 0.01, 'width');
    near(l.image.height, 100, 0.01, 'height');
});

if (failures > 0) {
    console.error(`\n${failures} failing`);
    process.exit(1);
}

console.log('\nall passing');
