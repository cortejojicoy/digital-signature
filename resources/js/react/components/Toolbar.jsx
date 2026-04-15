import { useRef } from 'react';

const SWATCHES = ['#1a1a1a', '#1e3a8a', '#166534', '#7f1d1d', '#6b21a8'];

export function Toolbar({ state, dispatch, confirmLabel, showClear, showUndo, onConfirm }) {
    const isEmpty = state.strokes.length === 0;

    return (
        <div
            style={{
                display:        'flex',
                alignItems:     'center',
                gap:            '8px',
                padding:        '6px 10px',
                borderTop:      '1px solid var(--color-border-tertiary, rgba(0,0,0,.1))',
                background:     'var(--color-background-secondary, #f9f9f9)',
                flexWrap:       'wrap',
            }}
        >
            {/* Color swatches */}
            <div style={{ display: 'flex', gap: '4px', alignItems: 'center' }}>
                {SWATCHES.map(color => (
                    <button
                        key={color}
                        type="button"
                        title={color}
                        onClick={() => dispatch({ type: 'SET_PEN_COLOR', color })}
                        style={{
                            width:        '18px',
                            height:       '18px',
                            borderRadius: '50%',
                            background:   color,
                            border:       state.penColor === color
                                            ? '2px solid var(--color-primary-500, #6366f1)'
                                            : '2px solid transparent',
                            cursor:       'pointer',
                            flexShrink:   0,
                        }}
                    />
                ))}
            </div>

            {/* Thickness slider */}
            <input
                type="range"
                min={state.minWidth}
                max={state.maxWidth}
                step="0.1"
                value={state.penWidth}
                onChange={e => dispatch({ type: 'SET_PEN_WIDTH', width: parseFloat(e.target.value) })}
                title="Pen thickness"
                style={{ width: '64px', cursor: 'pointer' }}
            />

            <div style={{ flex: 1 }} />

            {/* Undo */}
            {showUndo && (
                <button
                    type="button"
                    disabled={state.undoStack.length === 0}
                    onClick={() => dispatch({ type: 'UNDO' })}
                    title="Undo last stroke"
                    style={btnStyle(false)}
                >
                    Undo
                </button>
            )}

            {/* Clear */}
            {showClear && (
                <button
                    type="button"
                    disabled={isEmpty}
                    onClick={() => dispatch({ type: 'CLEAR' })}
                    title="Clear canvas"
                    style={btnStyle(false)}
                >
                    Clear
                </button>
            )}

            {/* Confirm */}
            <button
                type="button"
                disabled={isEmpty}
                onClick={onConfirm}
                style={btnStyle(true, isEmpty)}
            >
                {confirmLabel ?? 'Confirm signature'}
            </button>
        </div>
    );
}

function btnStyle(primary = false, disabled = false) {
    return {
        padding:       '4px 12px',
        borderRadius:  '6px',
        fontSize:      '12px',
        fontWeight:    '500',
        cursor:        disabled ? 'not-allowed' : 'pointer',
        opacity:       disabled ? 0.45 : 1,
        border:        primary ? 'none' : '1px solid var(--color-border-secondary, rgba(0,0,0,.2))',
        background:    primary
                         ? 'var(--color-primary-600, #4f46e5)'
                         : 'var(--color-background-primary, #fff)',
        color:         primary
                         ? '#fff'
                         : 'var(--color-text-secondary, #555)',
        transition:    'opacity 0.15s, background 0.15s',
    };
}