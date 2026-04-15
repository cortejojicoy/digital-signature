import { useReducer, useEffect, useCallback } from 'react';
import { createRoot }   from 'react-dom/client';
import { Canvas }       from './components/Canvas.jsx';
import { Toolbar }      from './components/Toolbar.jsx';
import { reducer, initialState } from './store/signatureStore.js';
import { useBridgeEmit } from './hooks/useBridgeEmit.js';
import { canvasToBase64 } from '../utils/canvasToBase64.js';

function SignaturePadIsland({ el }) {
    const config = {
        fieldId:      el.dataset.fieldId      ?? '',
        penColor:     el.dataset.penColor     ?? '#1a1a1a',
        canvasWidth:  parseInt(el.dataset.canvasWidth  ?? '600', 10),
        canvasHeight: parseInt(el.dataset.canvasHeight ?? '200', 10),
        minPenWidth:  parseFloat(el.dataset.minPenWidth ?? '0.8'),
        maxPenWidth:  parseFloat(el.dataset.maxPenWidth ?? '2.5'),
        showClear:    el.dataset.showClear    !== 'false',
        showUndo:     el.dataset.showUndo     !== 'false',
        confirmLabel: el.dataset.confirmLabel ?? 'Confirm signature',
    };

    const [state, dispatch] = useReducer(reducer, {
        ...initialState,
        penColor: config.penColor,
        minWidth: config.minPenWidth,
        maxWidth: config.maxPenWidth,
        penWidth: config.minPenWidth,
    });

    const { emit, listenClear } = useBridgeEmit(config.fieldId);

    // Listen for Alpine's clear signal
    useEffect(() => listenClear(() => dispatch({ type: 'CLEAR' })), []);

    const handleConfirm = useCallback(() => {
        // Find the actual <canvas> inside our mount node
        const canvas = el.querySelector('canvas');
        if (!canvas) return;
        const png = canvasToBase64(canvas);
        dispatch({ type: 'SET_EXPORTED', png });
        emit(png); // → Alpine onExported()
    }, [el, emit]);

    return (
        <div style={{ display: 'flex', flexDirection: 'column', width: '100%', height: '100%' }}>
            <Canvas
                width={config.canvasWidth}
                height={config.canvasHeight}
                state={state}
                dispatch={dispatch}
            />
            <Toolbar
                state={state}
                dispatch={dispatch}
                confirmLabel={config.confirmLabel}
                showClear={config.showClear}
                showUndo={config.showUndo}
                onConfirm={handleConfirm}
            />
        </div>
    );
}

// ── Mount / unmount helpers (called by Alpine index.js bootstrap) ────────────

export function mountIsland(el) {
    const root = createRoot(el);
    root.render(<SignaturePadIsland el={el} />);
    return root;   // stored in WeakMap by the bootstrap
}

export function unmountIsland(root) {
    root?.unmount();
}
