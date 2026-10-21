/**
 * One answer from two checks: what the file says about itself
 * (pdfSignature.inspectPdf) and what the record says about the file
 * (POST /signature/verify/lookup).
 *
 * The record decides whenever it recognises the exact bytes: a file that is
 * byte-for-byte a version the system issued is that version, whatever else
 * is true. Only when it doesn't does the local check get a say, and a broken
 * embedded signature outranks "not issued" because it tells the holder more:
 * this file was signed and then altered.
 *
 * Pure, so the rules are tested without a browser (tests/js/verdict.test.mjs).
 */

export const RECORD_VERDICTS = ['complete', 'in_progress', 'superseded', 'withdrawn'];

/**
 * @param {{ signature: null|{ intact: boolean } }} inspection
 * @param {null|{ verdict: string }} record  null when the lookup failed
 * @returns {string} a verdict key of COPY
 */
export function combineVerdict(inspection, record) {
    if (record && RECORD_VERDICTS.includes(record.verdict)) {
        return record.verdict;
    }

    if (inspection.signature && inspection.signature.intact === false) {
        return 'invalid_signature';
    }

    if (record && record.verdict === 'signature_valid_file_changed') {
        return 'signature_valid_file_changed';
    }

    if (record === null) {
        return 'lookup_failed';
    }

    return 'not_issued';
}

/**
 * What to say for each verdict. `tone` picks the colour and mark; the text is
 * written for the person holding the file, not for whoever built the system.
 */
export const COPY = {
    complete: {
        tone: 'ok', mark: '✓',
        title: 'Signed copy of record',
        lede: ({ issuer }) => `This file is exactly the copy ${issuer} holds, and every signatory has signed it.`,
    },
    in_progress: {
        tone: 'warn', mark: '…',
        title: 'Genuine, but not finished',
        lede: ({ document }) => `This is a real copy, but only ${document.signed} of ${document.required} signatures are on it. It isn't a finished document yet.`,
    },
    superseded: {
        tone: 'warn', mark: '↻',
        title: 'Genuine earlier copy',
        lede: () => 'This is a real copy, but more signatures were added after it. Ask for the latest copy.',
    },
    withdrawn: {
        tone: 'warn', mark: '–',
        title: 'Signing was stopped',
        lede: () => 'This is a real copy, but signing was cancelled or ran out of time before everyone signed.',
    },
    signature_valid_file_changed: {
        tone: 'bad', mark: '!',
        title: 'Changed after signing',
        lede: () => 'The signatures are real, but this file was edited and saved after the last one. What you see may not be what was signed.',
    },
    invalid_signature: {
        tone: 'bad', mark: '!',
        title: 'The signature is broken',
        lede: () => 'The digital signature in this file doesn\'t match its contents, so the file was altered after it was signed.',
    },
    not_issued: {
        tone: 'bad', mark: '✕',
        title: ({ issuer }) => `Not issued by ${issuer}`,
        lede: ({ issuer }) => `This file doesn't match any document ${issuer} issued. Treat any signatures shown on it as unverified.`,
    },
    lookup_failed: {
        tone: 'info', mark: '?',
        title: 'Couldn\'t check the record',
        lede: () => 'The server didn\'t answer, so this only shows what the file says about itself. Try again in a moment.',
    },
};

/** Plain-language state for one slot row, plus a chip for the certificate. */
export function describeSlot(slot) {
    switch (slot.state) {
        case 'signed':
            return {
                text: `Signed by ${slot.signer ?? 'a signatory'}`,
                chip: slot.certificate === 'revoked'
                    ? { tone: 'bad', text: 'Certificate since revoked' }
                    : slot.certificate === 'unchecked'
                        ? { tone: 'info', text: 'Certificate not checked' }
                        : null,
            };
        case 'signed_later':
            return { text: 'Signed in a later copy, not this one', chip: null };
        case 'declined':
            return { text: 'Declined', chip: { tone: 'bad', text: 'Declined' } };
        default:
            return { text: 'Not signed yet', chip: null };
    }
}

/** What the file's own signature says, as label/value rows. */
export function describeEmbedded(inspection, issuer) {
    const signature = inspection.signature;

    if (signature === null) {
        return [['Digital signature', 'None in this file']];
    }

    const rows = [[
        'Digital signature',
        signature.intact ? `Intact${signature.signer ? `, made by ${signature.signer}` : ''}` : 'Broken: doesn\'t match the file',
    ]];

    if (signature.intact) {
        rows.push([
            'Certificate',
            signature.trusted === true
                ? `Issued under ${issuer}'s certificate authority`
                : signature.trusted === false
                    ? 'Issued by a certificate authority this system doesn\'t trust'
                    : 'Not checked',
        ]);
    }

    if (!signature.coversWholeFile) {
        rows.push(['Saved since signing', 'Yes, bytes were added after the last signature']);
    }

    return rows;
}
