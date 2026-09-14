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

    assert.ok(l.qr !== null, 'expected a QR');
    assert.ok(l.caption.lines.length > 0, 'expected caption lines');
    // The three shares must account for the box and never exceed it.
    assert.ok(l.image.width + l.qr.size <= 220, 'image + QR overflows the width');
    assert.ok(l.image.height + l.caption.height <= 70 + 0.01, 'image + caption overflows the height');
});

test('stands the caption down on a box too short to share', () => {
    const l = layoutStamp({ width: 160, height: 18 }, LINES);

    assert.deepEqual(l.caption.lines, []);
    near(l.image.height, 18, 0.01, 'image height');
});

test('stands the QR down rather than printing one too small to scan', () => {
    const l = layoutStamp({ width: 60, height: 16 }, LINES);

    assert.equal(l.qr, null);
    near(l.image.width, 60, 0.01, 'image width');
});

test('never lets the QR take more than a third of the width', () => {
    const l = layoutStamp({ width: 90, height: 90 }, LINES);

    assert.ok(l.qr.size <= 30 + 0.01, `QR took ${l.qr.size} of 90`);
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
    assert.equal(l.qr, null);
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

    assert.ok(l.qr !== null, 'a default box should be able to carry a QR');
    assert.ok(l.image.width > l.qr.size, 'the signature should still be the larger mark');
});

test('never proposes a box wider than the page allows', () => {
    const box = defaultStampBox(4, 100);

    assert.ok(box.width <= 100, `box was ${box.width} on a 100pt page`);
});

test('puts the caption on whichever side it is told', () => {
    const box = { width: 220, height: 70 };

    const bottom = layoutStamp(box, LINES, undefined, 'bottom');
    const top    = layoutStamp(box, LINES, undefined, 'top');
    const left   = layoutStamp(box, LINES, undefined, 'left');
    const right  = layoutStamp(box, LINES, undefined, 'right');

    // Horizontal sides take height and leave the full width to the content.
    assert.equal(bottom.caption.y > top.caption.y, true, 'bottom should sit lower than top');
    assert.equal(top.image.y > 0, true, 'a top caption should push the ink down');
    assert.equal(bottom.image.y, 0, 'a bottom caption should leave the ink at the top');

    // Vertical sides take width instead.
    assert.equal(left.image.x > 0, true, 'a left caption should push the ink right');
    assert.equal(right.image.x, 0, 'a right caption should leave the ink at the left');
    assert.ok(left.image.height > bottom.image.height,
        'a side caption should give the ink its full height back');
});

test('keeps every element inside the box on all four sides', () => {
    const box = { width: 220, height: 70 };

    for (const side of ['bottom', 'top', 'left', 'right']) {
        const l = layoutStamp(box, LINES, undefined, side);

        assert.ok(l.image.x >= -0.01 && l.image.y >= -0.01, `${side}: ink outside the box`);
        assert.ok(l.image.x + l.image.width  <= box.width  + 0.01, `${side}: ink overflows width`);
        assert.ok(l.image.y + l.image.height <= box.height + 0.01, `${side}: ink overflows height`);

        if (l.qr) {
            assert.ok(l.qr.x + l.qr.size <= box.width  + 0.01, `${side}: QR overflows width`);
            assert.ok(l.qr.y + l.qr.size <= box.height + 0.01, `${side}: QR overflows height`);
        }

        assert.ok(l.caption.x + l.caption.w <= box.width + 0.01, `${side}: caption overflows width`);
        assert.ok(l.caption.y + l.caption.height <= box.height + 0.01, `${side}: caption overflows height`);
    }
});

test('stands a side caption down on a box too narrow for a column', () => {
    // Beside the ink the limit is horizontal — a sliver of signature is worse
    // than an uncaptioned one.
    const l = layoutStamp({ width: 80, height: 70 }, LINES, undefined, 'left');

    assert.deepEqual(l.caption.lines, []);
    near(l.image.x, 0, 0.01, 'ink should reclaim the whole width');
});

test('falls back to the configured side for an unknown one', () => {
    const l = layoutStamp({ width: 220, height: 70 }, LINES,
        { caption: { position: 'top' } }, 'sideways');

    assert.equal(l.position, 'top');
});

test('gives a side caption its width back when sizing a dropped box', () => {
    const beside = defaultStampBox(4, 600, { caption: { position: 'left' } });
    const below  = defaultStampBox(4, 600, { caption: { position: 'bottom' } });

    assert.ok(beside.width > below.width,
        'a caption beside the ink needs a wider box, not a taller one');
});

test('keeps the caption tucked under the ink at every box size', () => {
    // The bug this guards: the caption was pinned to the bottom edge, so
    // enlarging the placement pushed the provenance further and further from
    // the signature it describes until it read as a note belonging to whatever
    // the form had underneath.
    for (const height of [40, 70, 120, 240]) {
        const l = layoutStamp({ width: 300, height }, LINES, undefined, 'bottom', 4);
        const inkBottom = l.image.y + l.image.height;

        near(l.caption.y, inkBottom, 0.01, `caption gap at height ${height}`);
    }
});

test('centres the ink-and-caption group rather than the ink alone', () => {
    const box = { width: 300, height: 200 };
    const l = layoutStamp(box, LINES, undefined, 'bottom', 4);

    const groupTop = l.image.y;
    const groupBottom = l.caption.y + l.caption.height;

    near(groupTop, box.height - groupBottom, 0.5, 'group centring');
});

test('keeps the signature at its own proportions', () => {
    // Stretching a signature to fill whatever rectangle was drawn produces a
    // stamp that no longer matches the specimen on file.
    for (const aspect of [1, 2.5, 6]) {
        const l = layoutStamp({ width: 300, height: 160 }, LINES, undefined, 'bottom', aspect);

        near(l.image.width / l.image.height, aspect, 0.01, `aspect ${aspect}`);
    }
});

test('fills the space when there is no aspect to honour', () => {
    const l = layoutStamp({ width: 300, height: 100 }, [], { caption: { enabled: false }, qr: { enabled: false } });

    near(l.image.width, 300, 0.01, 'width');
    near(l.image.height, 100, 0.01, 'height');
});

test('levels the QR with the ink instead of floating it above', () => {
    const l = layoutStamp({ width: 300, height: 200 }, LINES, undefined, 'bottom', 4);

    assert.ok(l.qr, 'expected a QR');
    const inkMid = l.image.y + l.image.height / 2;
    const qrMid  = l.qr.y + l.qr.size / 2;

    near(qrMid, inkMid, 0.5, 'QR centre against ink centre');
});

if (failures > 0) {
    console.error(`\n${failures} failing`);
    process.exit(1);
}

console.log('\nall passing');
