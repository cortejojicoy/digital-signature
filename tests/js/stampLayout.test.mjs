/**
 * How a stamp divides its box, in the browser.
 *
 * This is the preview half of a layout the PDF writer also performs, so the
 * cases below are the ones where the two must agree: which bands exist, how
 * tall they are, and when each stands down. If these drift, a signatory
 * aligns a box against a form and something else prints into it.
 *
 * Run with: npm run test:js   (node only, no dependencies)
 */
import assert from 'node:assert/strict';
import { layoutStamp, defaultStampBox } from '../../resources/js/utils/stampLayout.js';

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

const LINES = ['Paolo Rommel P. Sanchez', 'Signed 14 Sep 2026 03:49', 'Ref aef7f9a1'];

console.log('stampLayout');

test('splits the box between ink, QR and caption', () => {
    const l = layoutStamp({ width: 220, height: 70 }, LINES);

    assert.ok(l.qr > 0, 'expected a QR');
    assert.ok(l.caption.lines.length > 0, 'expected caption lines');
    // The three shares must account for the box and never exceed it.
    assert.ok(l.image.width + l.qr <= 220, 'image + QR overflows the width');
    assert.ok(l.image.height + l.caption.height <= 70 + 0.01, 'image + caption overflows the height');
});

test('stands the caption down on a box too short to share', () => {
    const l = layoutStamp({ width: 160, height: 18 }, LINES);

    assert.deepEqual(l.caption.lines, []);
    near(l.image.height, 18, 0.01, 'image height');
});

test('stands the QR down rather than printing one too small to scan', () => {
    const l = layoutStamp({ width: 60, height: 16 }, LINES);

    assert.equal(l.qr, 0);
    near(l.image.width, 60, 0.01, 'image width');
});

test('never lets the QR take more than a third of the width', () => {
    const l = layoutStamp({ width: 90, height: 90 }, LINES);

    assert.ok(l.qr <= 30 + 0.01, `QR took ${l.qr} of 90`);
});

test('drops caption lines that do not fit instead of overflowing', () => {
    const roomy = layoutStamp({ width: 260, height: 90 }, LINES);
    const tight = layoutStamp({ width: 260, height: 34 }, LINES);

    assert.ok(tight.caption.lines.length < roomy.caption.lines.length,
        'a shorter box should hold fewer lines');
    assert.ok(tight.caption.height <= 34 * 0.38 + 0.01, 'caption band overflowed its ratio');
});

test('honours rules sent by the server over its own defaults', () => {
    const l = layoutStamp({ width: 220, height: 70 }, LINES, {
        caption: { enabled: false },
        qr: { enabled: false },
    });

    assert.deepEqual(l.caption.lines, []);
    assert.equal(l.qr, 0);
    // With neither extra, the ink gets the whole box.
    near(l.image.width, 220, 0.01, 'image width');
    near(l.image.height, 70, 0.01, 'image height');
});

test('sizes a dropped box so the ink keeps its own shape', () => {
    // A 4:1 signature should still come out roughly 4:1 in the image band,
    // not squashed by whatever the caption claims.
    const aspect = 4;
    const box = defaultStampBox(aspect, 600);
    const l = layoutStamp(box, LINES);

    near(l.image.width / l.image.height, aspect, 0.9, 'ink aspect');
});

test('gives a dropped box room for the QR beside the ink', () => {
    const box = defaultStampBox(4, 600);
    const l = layoutStamp(box, LINES);

    assert.ok(l.qr > 0, 'a default box should be able to carry a QR');
    assert.ok(l.image.width > l.qr, 'the signature should still be the larger mark');
});

test('never proposes a box wider than the page allows', () => {
    const box = defaultStampBox(4, 100);

    assert.ok(box.width <= 100, `box was ${box.width} on a 100pt page`);
});

if (failures > 0) {
    console.error(`\n${failures} failing`);
    process.exit(1);
}

console.log('\nall passing');
