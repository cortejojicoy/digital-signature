/**
 * How the verify page turns two checks into one answer.
 *
 * The rules that matter: an exact record match always wins; a broken
 * embedded signature outranks "not issued"; and a failed lookup is never
 * shown as "not issued", because "we couldn't ask" and "we asked and it
 * isn't ours" send the holder off to do very different things.
 *
 * Run with: npm run test:js
 */
import assert from 'node:assert/strict';
import { COPY, combineVerdict, describeEmbedded, describeSlot } from '../../resources/js/verify/verdict.js';

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

const intact = { signature: { intact: true, trusted: true, signer: 'Ben', coversWholeFile: true } };
const broken = { signature: { intact: false, trusted: null, signer: 'Ben', coversWholeFile: true } };
const unsigned = { signature: null };

console.log('verdict');

test('an exact record match wins over everything the file says', () => {
    for (const verdict of ['complete', 'in_progress', 'superseded', 'withdrawn']) {
        assert.equal(combineVerdict(broken, { verdict }), verdict);
        assert.equal(combineVerdict(unsigned, { verdict }), verdict);
    }
});

test('a broken signature outranks "not issued" and "changed after signing"', () => {
    assert.equal(combineVerdict(broken, { verdict: 'not_issued' }), 'invalid_signature');
    assert.equal(combineVerdict(broken, { verdict: 'signature_valid_file_changed' }), 'invalid_signature');
});

test('an intact signature on a file saved since is "changed after signing"', () => {
    assert.equal(combineVerdict(intact, { verdict: 'signature_valid_file_changed' }), 'signature_valid_file_changed');
});

test('no match and no signature is "not issued"', () => {
    assert.equal(combineVerdict(unsigned, { verdict: 'not_issued' }), 'not_issued');
    assert.equal(combineVerdict(intact, { verdict: 'not_issued' }), 'not_issued');
});

test('a failed lookup is never reported as "not issued"', () => {
    assert.equal(combineVerdict(intact, null), 'lookup_failed');
    assert.equal(combineVerdict(unsigned, null), 'lookup_failed');
    // But a broken signature is still broken, whether or not the server answered.
    assert.equal(combineVerdict(broken, null), 'invalid_signature');
});

test('every verdict the page can reach has words', () => {
    const context = { issuer: 'UPLB Performance', document: { signed: 2, required: 4 } };

    for (const key of ['complete', 'in_progress', 'superseded', 'withdrawn', 'signature_valid_file_changed', 'invalid_signature', 'not_issued', 'lookup_failed']) {
        const copy = COPY[key];
        assert.ok(copy, key);
        const title = typeof copy.title === 'function' ? copy.title(context) : copy.title;
        assert.ok(title.length > 0, `${key} title`);
        assert.ok(copy.lede(context).length > 0, `${key} lede`);
    }

    assert.match(COPY.in_progress.lede(context), /2 of 4/);
});

test('a slot signed after this copy is not shown as signed on it', () => {
    assert.equal(describeSlot({ state: 'signed_later' }).text, 'Signed in a later copy, not this one');
    assert.equal(describeSlot({ state: 'signed', signer: 'Ana' }).text, 'Signed by Ana');
    assert.equal(describeSlot({ state: 'signed', signer: 'Ana', certificate: 'revoked' }).chip.text, 'Certificate since revoked');
});

test('describes the embedded signature without overclaiming trust', () => {
    const rows = Object.fromEntries(describeEmbedded({ signature: { intact: true, trusted: null, signer: 'Ben', coversWholeFile: false } }, 'X'));

    assert.equal(rows['Certificate'], 'Not checked');
    assert.ok(rows['Saved since signing']);
    assert.equal(Object.fromEntries(describeEmbedded(unsigned, 'X'))['Digital signature'], 'None in this file');
});

if (failures > 0) {
    console.error(`\n${failures} failing`);
    process.exit(1);
}
