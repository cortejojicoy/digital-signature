
import signatureField   from './alpine/signatureField.js';
import signaturePreview from './alpine/signaturePreview.js';
import { mountIsland, unmountIsland } from './react/SignaturePadIsland.jsx';
import { mountDesigner, unmountDesigner } from './react/PdfDesignerIsland.jsx';

// ── Alpine plugin registration ───────────────────────────────────────────────

export default function SignaturePlugin(Alpine) {
    Alpine.data('signatureField',   signatureField);
    Alpine.data('signaturePreview', signaturePreview);
}

// Auto-register when bundled and loaded directly (Filament asset).
document.addEventListener('alpine:init', () => {
    if (window.Alpine) {
        window.Alpine.plugin(SignaturePlugin);
    }
});

// ── React island bootstrap ───────────────────────────────────────────────────
//
// Each entry maps a DOM data-attribute to its mount/unmount pair. The
// observer below watches the document for elements carrying any of these
// attributes and mounts the matching island. New islands plug in here.

const ISLANDS = [
    {
        attr:    'data-signature-canvas',
        mount:   mountIsland,
        unmount: unmountIsland,
    },
    {
        attr:    'data-pdf-designer',
        mount:   mountDesigner,
        unmount: unmountDesigner,
    },
];

const mounted = new WeakMap();  // element → { unmount, root }

function tryMount(node) {
    if (node.nodeType !== Node.ELEMENT_NODE) return;
    for (const { attr, mount, unmount } of ISLANDS) {
        // The node itself
        if (node.hasAttribute?.(attr) && !mounted.has(node)) {
            mounted.set(node, { root: mount(node), unmount });
        }
        // Any descendants
        node.querySelectorAll?.(`[${attr}]`).forEach((el) => {
            if (!mounted.has(el)) mounted.set(el, { root: mount(el), unmount });
        });
    }
}

function tryUnmount(node) {
    if (node.nodeType !== Node.ELEMENT_NODE) return;
    for (const { attr } of ISLANDS) {
        if (node.hasAttribute?.(attr) && mounted.has(node)) {
            const m = mounted.get(node);
            m.unmount(m.root);
            mounted.delete(node);
        }
        node.querySelectorAll?.(`[${attr}]`).forEach((el) => {
            if (mounted.has(el)) {
                const m = mounted.get(el);
                m.unmount(m.root);
                mounted.delete(el);
            }
        });
    }
}

const observer = new MutationObserver((mutations) => {
    for (const { addedNodes, removedNodes } of mutations) {
        addedNodes.forEach(tryMount);
        removedNodes.forEach(tryUnmount);
    }
});

function bootstrap() {
    observer.observe(document.body, { childList: true, subtree: true });
    for (const { attr } of ISLANDS) {
        document.querySelectorAll(`[${attr}]`).forEach(tryMount);
    }
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', bootstrap);
} else {
    bootstrap();
}
