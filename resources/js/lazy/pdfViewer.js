/**
 * Loads the PDF viewer bundle the first time one is needed, then mounts into
 * the element that asked for it.
 *
 * The mount/unmount pair below has the same shape as every other island's, so
 * the bootstrap in index.js does not need to know that this one arrives late.
 * The handle it returns stands in for a React root until the real one exists —
 * an element removed from the DOM mid-download is marked disposed and never
 * gets mounted at all.
 */
let loader = null;

function loadBundle(src) {
    if (window.DsigPdfViewer) return Promise.resolve(window.DsigPdfViewer);

    if (!loader) {
        loader = new Promise((resolve, reject) => {
            if (!src) {
                reject(new Error('No PDF viewer bundle URL was provided.'));
                return;
            }

            const script = document.createElement('script');
            script.src   = src;
            script.async = true;
            script.onload = () => window.DsigPdfViewer
                ? resolve(window.DsigPdfViewer)
                : reject(new Error('The PDF viewer bundle loaded but registered nothing.'));
            script.onerror = () => {
                // Let a later attempt retry rather than caching the failure
                // for the life of the page.
                loader = null;
                reject(new Error('Could not load the PDF viewer bundle.'));
            };
            document.head.appendChild(script);
        });
    }

    return loader;
}

export function mountLazyViewer(el) {
    const handle = { disposed: false, api: null, root: null };

    loadBundle(el.dataset.bundleSrc)
        .then((api) => {
            if (handle.disposed) return;
            handle.api  = api;
            handle.root = api.mount(el);
        })
        .catch((e) => {
            if (handle.disposed) return;
            el.textContent = e.message;
        });

    return handle;
}

export function unmountLazyViewer(handle) {
    handle.disposed = true;
    if (handle.root && handle.api) {
        handle.api.unmount(handle.root);
        handle.root = null;
    }
}
