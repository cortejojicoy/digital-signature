export function useBridgeEmit(fieldId) {
    const emit = (png) => {
        window.dispatchEvent(new CustomEvent('sig:exported', {
            detail: { png, fieldId },
            bubbles: false,
        }));
    };

    const listenClear = (callback) => {
        const handler = (e) => {
            if (!fieldId || e.detail?.fieldId === fieldId) callback();
        };
        window.addEventListener('sig:clear', handler);
        return () => window.removeEventListener('sig:clear', handler);
    };

    return { emit, listenClear };
}