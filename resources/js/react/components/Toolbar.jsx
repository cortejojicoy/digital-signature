const SWATCHES = [
    { color: '#1a1a1a', label: 'Black' },
    { color: '#1e3a8a', label: 'Navy' },
    { color: '#166534', label: 'Forest' },
    { color: '#7f1d1d', label: 'Crimson' },
    { color: '#6b21a8', label: 'Violet' },
];

const BRUSH_SIZES = [
    { key: 'S', min: 0.5, max: 1.5,  dot: 4  },
    { key: 'M', min: 1.0, max: 2.5,  dot: 7  },
    { key: 'L', min: 2.0, max: 4.5,  dot: 11 },
];

function activeBrushKey(maxWidth) {
    if (maxWidth <= 1.5) return 'S';
    if (maxWidth <= 2.5) return 'M';
    return 'L';
}

export function Toolbar({ state, dispatch, confirmLabel, showClear, showUndo, onConfirm }) {
    const isEmpty     = state.strokes.length === 0;
    const activeSize  = activeBrushKey(state.maxWidth);

    return (
        <div style={{
            display:     'flex',
            alignItems:  'center',
            gap:         '8px',
            padding:     '7px 12px',
            borderTop:   '1px solid rgba(0,0,0,0.07)',
            background:  'var(--color-bg-secondary, #f8f9fa)',
            flexWrap:    'wrap',
        }}>

            {/* ── Color swatches ─────────────────────────────────────────── */}
            <div style={{ display: 'flex', gap: '5px', alignItems: 'center' }}>
                {SWATCHES.map(({ color, label }) => {
                    const active = state.penColor === color;
                    return (
                        <button
                            key={color}
                            type="button"
                            title={label}
                            onClick={() => dispatch({ type: 'SET_PEN_COLOR', color })}
                            style={{
                                width:        '20px',
                                height:       '20px',
                                borderRadius: '50%',
                                background:   color,
                                border:       active
                                    ? '2px solid #fff'
                                    : '2px solid transparent',
                                boxShadow:    active
                                    ? `0 0 0 2.5px ${color}`
                                    : '0 1px 3px rgba(0,0,0,0.25)',
                                cursor:       'pointer',
                                transform:    active ? 'scale(1.18)' : 'scale(1)',
                                transition:   'transform 0.12s, box-shadow 0.12s',
                                flexShrink:   0,
                            }}
                        />
                    );
                })}
            </div>

            {/* ── Divider ────────────────────────────────────────────────── */}
            <div style={{ width: '1px', height: '22px', background: 'rgba(0,0,0,0.09)', flexShrink: 0 }} />

            {/* ── Brush size picker ──────────────────────────────────────── */}
            <div
                title="Brush size"
                style={{
                    display:      'flex',
                    gap:          '2px',
                    alignItems:   'center',
                    background:   'rgba(0,0,0,0.05)',
                    borderRadius: '8px',
                    padding:      '3px',
                }}
            >
                {BRUSH_SIZES.map(({ key, min, max, dot }) => {
                    const selected = activeSize === key;
                    return (
                        <button
                            key={key}
                            type="button"
                            title={`${key === 'S' ? 'Fine' : key === 'M' ? 'Medium' : 'Bold'} brush`}
                            onClick={() => dispatch({ type: 'SET_CONFIG', minWidth: min, maxWidth: max })}
                            style={{
                                width:        '28px',
                                height:       '28px',
                                borderRadius: '6px',
                                border:       'none',
                                cursor:       'pointer',
                                background:   selected ? 'var(--color-primary-500, #6366f1)' : 'transparent',
                                display:      'flex',
                                alignItems:   'center',
                                justifyContent: 'center',
                                transition:   'background 0.12s',
                            }}
                        >
                            <div style={{
                                width:        dot,
                                height:       dot,
                                borderRadius: '50%',
                                background:   selected ? '#fff' : state.penColor,
                                flexShrink:   0,
                                transition:   'background 0.12s',
                            }} />
                        </button>
                    );
                })}
            </div>

            <div style={{ flex: 1 }} />

            {/* ── Undo ───────────────────────────────────────────────────── */}
            {showUndo && (
                <button
                    type="button"
                    disabled={state.undoStack.length === 0}
                    onClick={() => dispatch({ type: 'UNDO' })}
                    title="Undo last stroke"
                    style={actionBtn(state.undoStack.length === 0)}
                >
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" strokeWidth="2.2"
                         strokeLinecap="round" strokeLinejoin="round">
                        <path d="M3 7v6h6"/><path d="M21 17a9 9 0 00-9-9 9 9 0 00-6 2.3L3 13"/>
                    </svg>
                </button>
            )}

            {/* ── Clear ──────────────────────────────────────────────────── */}
            {showClear && (
                <button
                    type="button"
                    disabled={isEmpty}
                    onClick={() => dispatch({ type: 'CLEAR' })}
                    title="Clear canvas"
                    style={actionBtn(isEmpty)}
                >
                    <svg width="13" height="13" viewBox="0 0 24 24" fill="none"
                         stroke="currentColor" strokeWidth="2.2"
                         strokeLinecap="round" strokeLinejoin="round">
                        <line x1="18" y1="6" x2="6" y2="18"/>
                        <line x1="6"  y1="6" x2="18" y2="18"/>
                    </svg>
                </button>
            )}

            {/* ── Confirm ────────────────────────────────────────────────── */}
            <button
                type="button"
                disabled={isEmpty}
                onClick={onConfirm}
                style={{
                    display:      'inline-flex',
                    alignItems:   'center',
                    gap:          '5px',
                    padding:      '5px 14px',
                    borderRadius: '8px',
                    fontSize:     '12px',
                    fontWeight:   '600',
                    cursor:       isEmpty ? 'not-allowed' : 'pointer',
                    opacity:      isEmpty ? 0.4 : 1,
                    border:       'none',
                    background:   isEmpty
                        ? 'rgba(0,0,0,0.1)'
                        : 'var(--color-primary-600, #4f46e5)',
                    color:        isEmpty ? 'rgba(0,0,0,0.4)' : '#fff',
                    transition:   'opacity 0.15s, background 0.15s',
                    whiteSpace:   'nowrap',
                }}
            >
                <svg width="13" height="13" viewBox="0 0 20 20" fill="currentColor">
                    <path fillRule="evenodd" clipRule="evenodd"
                          d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0
                             01-1.414 0l-4-4a1 1 0 011.414-1.414L8
                             12.586l7.293-7.293a1 1 0 011.414 0z"/>
                </svg>
                {confirmLabel ?? 'Confirm'}
            </button>
        </div>
    );
}

function actionBtn(disabled) {
    return {
        width:         '30px',
        height:        '30px',
        display:       'flex',
        alignItems:    'center',
        justifyContent:'center',
        borderRadius:  '7px',
        border:        '1px solid rgba(0,0,0,0.1)',
        background:    'transparent',
        color:         disabled ? '#d1d5db' : '#6b7280',
        cursor:        disabled ? 'not-allowed' : 'pointer',
        opacity:       disabled ? 0.45 : 1,
        transition:    'color 0.12s, background 0.12s',
    };
}
