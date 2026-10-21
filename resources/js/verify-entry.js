/**
 * The "drop a PDF here" page (resources/views/verify-document.blade.php).
 *
 * Reads the chosen file in the browser, checks its embedded signature
 * (verify/pdfSignature.js), sends only its fingerprints to the lookup, and
 * shows one verdict (verify/verdict.js) with a row per signature slot.
 *
 * Every string from the server goes in through textContent: signer names are
 * data, and this page is public.
 */
import { inspectPdf, parsePemCertificates } from './verify/pdfSignature.js';
import { COPY, combineVerdict, describeEmbedded, describeSlot } from './verify/verdict.js';

const MAX_BYTES = 50 * 1024 * 1024;

const app = document.getElementById('verify-app');

if (app) {
    const lookupUrl = app.dataset.lookupUrl;
    const trustUrl = app.dataset.trustUrl;
    const issuer = app.dataset.issuer || 'this system';

    const input = document.getElementById('verify-file');
    const drop = document.getElementById('verify-drop');
    const error = document.getElementById('verify-error');
    const result = document.getElementById('verify-result');

    let trustAnchors = null;

    /** The CA to check chains against. Fetched once; [] means "couldn't", i.e. trust unchecked. */
    async function trusted() {
        if (trustAnchors === null) {
            trustAnchors = fetch(trustUrl, { headers: { Accept: 'application/x-pem-file' } })
                .then((r) => (r.ok ? r.text() : ''))
                .then((pem) => parsePemCertificates(pem))
                .catch(() => []);
        }

        return trustAnchors;
    }

    async function lookup(inspection) {
        const revision = inspection.signedRevisionSha256;

        try {
            const response = await fetch(lookupUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
                body: JSON.stringify({
                    sha256: inspection.sha256,
                    signed_revision_sha256: revision && revision !== inspection.sha256 ? revision : null,
                }),
            });

            return response.ok ? await response.json() : null;
        } catch {
            return null;
        }
    }

    function el(tag, attrs = {}, ...children) {
        const node = document.createElement(tag);

        for (const [key, value] of Object.entries(attrs)) {
            if (key === 'class') node.className = value;
            else node.setAttribute(key, value);
        }

        for (const child of children) {
            if (child !== null && child !== undefined) {
                node.append(child instanceof Node ? child : document.createTextNode(String(child)));
            }
        }

        return node;
    }

    function formatDate(iso) {
        if (!iso) return null;

        const date = new Date(iso);

        return Number.isNaN(date.getTime())
            ? null
            : date.toLocaleString(undefined, { dateStyle: 'long', timeStyle: 'short' });
    }

    function formatSize(bytes) {
        return bytes >= 1024 * 1024 ? `${(bytes / 1024 / 1024).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
    }

    function showError(message) {
        error.textContent = message;
        error.hidden = false;
    }

    function render(file, inspection, record) {
        const verdict = combineVerdict(inspection, record);
        const copy = COPY[verdict];
        const context = { issuer: record?.issuer || issuer, document: record?.document ?? null };
        const pick = (value) => (typeof value === 'function' ? value(context) : value);

        result.replaceChildren();
        result.className = `card tone-${copy.tone}`;

        result.append(
            el('div', { class: 'banner' },
                el('div', { class: 'mark', 'aria-hidden': 'true' }, copy.mark),
                el('h2', {}, pick(copy.title)),
                el('p', { class: 'lede' }, pick(copy.lede)),
            ),
            el('p', { class: 'file' }, `Checked: ${file.name} (${formatSize(file.size)})`),
        );

        if (record?.document && record.slots?.length) {
            const title = record.document.title ? `${record.document.title}: signatures` : 'Signatures';
            const list = el('ol', { class: 'slots' });

            for (const slot of record.slots) {
                const described = describeSlot(slot);
                const state = el('span', { class: 'slot-state' }, described.text);

                if (described.chip) {
                    state.append(el('span', { class: `chip chip-${described.chip.tone}` }, described.chip.text));
                }

                list.append(el('li', {},
                    el('span', { class: 'slot-label' }, slot.label),
                    state,
                    slot.signed_at ? el('span', { class: 'slot-meta' }, formatDate(slot.signed_at)) : null,
                ));
            }

            result.append(el('h3', {}, title), list);
        }

        const details = el('dl');

        for (const [label, value] of describeEmbedded(inspection, context.issuer)) {
            details.append(el('dt', {}, label), el('dd', {}, value));
        }

        result.append(el('h3', {}, 'This file'), details);

        const again = el('button', { type: 'button' }, 'Check another file');
        again.addEventListener('click', () => {
            result.hidden = true;
            input.value = '';
            input.focus();
        });

        result.append(
            el('div', { class: 'actions' }, again),
            el('p', { class: 'note' },
                'A record match means these exact bytes came from this system. Printouts and scans can\'t be checked here: ask for the original PDF.'),
        );

        result.hidden = false;
        result.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    async function check(file) {
        error.hidden = true;

        if (!file) return;

        if (file.size > MAX_BYTES) {
            showError('That file is larger than 50 MB. Choose the original PDF from the system.');
            return;
        }

        const head = new Uint8Array(await file.slice(0, 5).arrayBuffer());

        if (String.fromCharCode(...head) !== '%PDF-') {
            showError('That file isn\'t a PDF. Choose the PDF you were sent.');
            return;
        }

        result.hidden = false;
        result.className = 'card tone-info';
        result.replaceChildren(el('p', { class: 'lede' }, `Checking ${file.name}…`));

        try {
            const bytes = new Uint8Array(await file.arrayBuffer());
            const inspection = await inspectPdf(bytes, await trusted());
            const record = await lookup(inspection);

            render(file, inspection, record);
        } catch {
            result.hidden = true;
            showError('This file couldn\'t be read. It may be damaged; try downloading it again.');
        }
    }

    input.addEventListener('change', () => check(input.files?.[0]));

    for (const type of ['dragenter', 'dragover']) {
        drop.addEventListener(type, (event) => {
            event.preventDefault();
            drop.classList.add('is-over');
        });
    }

    for (const type of ['dragleave', 'drop']) {
        drop.addEventListener(type, () => drop.classList.remove('is-over'));
    }

    drop.addEventListener('drop', (event) => {
        event.preventDefault();
        check(event.dataTransfer?.files?.[0]);
    });
}
