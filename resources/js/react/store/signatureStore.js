export const initialState = {
    strokes:     [],        // Array<{ points: Point[], color, width }>
    undoStack:   [],        // copy of strokes before last stroke was added
    exportedPng: null,      // base64 string after Confirm
    isDrawing:   false,
    penColor:    '#1a1a1a',
    penWidth:    1.6,       // current resolved width (dynamic via pressure)
    minWidth:    0.8,
    maxWidth:    2.5,
};

export function reducer(state, action) {
    switch (action.type) {

        case 'STROKE_START':
            return {
                ...state,
                undoStack: [...state.strokes],
                strokes:   [...state.strokes, { points: [action.point], color: state.penColor, width: state.penWidth }],
                isDrawing: true,
            };

        case 'STROKE_MOVE': {
            if (!state.isDrawing) return state;
            const strokes = [...state.strokes];
            const last    = { ...strokes[strokes.length - 1] };
            last.points   = [...last.points, action.point];
            strokes[strokes.length - 1] = last;
            return { ...state, strokes };
        }

        case 'STROKE_END':
            return { ...state, isDrawing: false };

        case 'UNDO':
            return { ...state, strokes: [...state.undoStack], undoStack: [] };

        case 'CLEAR':
            return { ...state, strokes: [], undoStack: [], exportedPng: null };

        case 'SET_PEN_COLOR':
            return { ...state, penColor: action.color };

        case 'SET_PEN_WIDTH':
            return { ...state, penWidth: action.width };

        case 'SET_EXPORTED':
            return { ...state, exportedPng: action.png };

        case 'SET_CONFIG':
            return {
                ...state,
                penColor:  action.penColor  ?? state.penColor,
                minWidth:  action.minWidth   ?? state.minWidth,
                maxWidth:  action.maxWidth   ?? state.maxWidth,
                penWidth:  action.minWidth   ?? state.penWidth,
            };

        default:
            return state;
    }
}