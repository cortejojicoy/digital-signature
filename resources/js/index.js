
import signatureField   from './alpine/signatureField.js';
import signaturePreview from './alpine/signaturePreview.js';
import { mountIsland, unmountIsland } from './react/SignaturePadIsland.jsx';

// ── Alpine plugin registration ───────────────────────────────────────────────

/**
 * Usage in app:
 *   import SignaturePlugin from '@conduit/signature';
 *   Alpine.plugin(SignaturePlugin);
 */
export default function SignaturePlugin(Alpine) {
    Alpine.data('signatureField',   signatureField);
    Alpine.data('signaturePreview', signaturePreview);
}

// ── React island bootstrap ───────────────────────────────────────────────────

const MOUNT_ATTR = 'data-signature-canvas';
const mounted    = new WeakMap();  // tracks which elements have a React root

function handleNode(node) {
    if (node.nodeType !== Node.ELEMENT_NODE) return;

    // Mount on the node itself
    if (node.hasAttribute?.(MOUNT_ATTR) && !mounted.has(node)) {
        mounted.set(node, mountIsland(node));
    }

    // Mount on any descendants
    node.querySelectorAll?.(`[${MOUNT_ATTR}]`).forEach(el => {
        if (!mounted.has(el)) mounted.set(el, mountIsland(el));
    });
}

function handleRemovedNode(node) {
    if (node.nodeType !== Node.ELEMENT_NODE) return;

    if (node.hasAttribute?.(MOUNT_ATTR) && mounted.has(node)) {
        unmountIsland(mounted.get(node));
        mounted.delete(node);
    }

    node.querySelectorAll?.(`[${MOUNT_ATTR}]`).forEach(el => {
        if (mounted.has(el)) {
            unmountIsland(mounted.get(el));
            mounted.delete(el);
        }
    });
}

const observer = new MutationObserver(mutations => {
    for (const { addedNodes, removedNodes } of mutations) {
        addedNodes.forEach(handleNode);
        removedNodes.forEach(handleRemovedNode);
    }
});

// Start observing once the DOM is ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        observer.observe(document.body, { childList: true, subtree: true });
        document.querySelectorAll(`[${MOUNT_ATTR}]`).forEach(handleNode);
    });
} else {
    observer.observe(document.body, { childList: true, subtree: true });
    document.querySelectorAll(`[${MOUNT_ATTR}]`).forEach(handleNode);
}