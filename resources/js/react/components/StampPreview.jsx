/**
 * What the signature will actually look like once it is stamped.
 *
 * The placement box does not hold a signature alone; it holds the ink and,
 * beside it, the signatory's name and the time of signing (COA Circular
 * 2021-006, IV.C.13). Drawing only the ink meant a signatory aligned a box
 * against a form and then got something else printed into it.
 *
 * Everything here arrives in CSS pixels, already converted by the caller from
 * the PDF-point layout. This component measures nothing and decides nothing:
 * if it did, it would be a second opinion about a layout that has to have
 * exactly one.
 */
export function StampPreview({ imageUrl, image, caption }) {
    return (
        <div style={{ position: 'absolute', inset: 0, pointerEvents: 'none', userSelect: 'none' }}>
            {imageUrl && image && (
                <img
                    src={imageUrl}
                    alt=""
                    draggable={false}
                    style={{
                        position: 'absolute',
                        left:   `${image.x}px`,
                        top:    `${image.y}px`,
                        width:  `${image.width}px`,
                        height: `${image.height}px`,
                        objectFit: 'contain',
                    }}
                />
            )}

            {caption?.lines?.length > 0 && (
                <div
                    style={{
                        position: 'absolute',
                        left: `${caption.x}px`,
                        top:  `${caption.y}px`,
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: 'flex-start',
                    }}
                >
                    {caption.lines.map((line, i) => (
                        <span
                            key={i}
                            style={{
                                fontSize:   `${caption.sizePx}px`,
                                lineHeight: `${caption.lineHeightPx}px`,
                                fontFamily: 'Helvetica, Arial, sans-serif',
                                color: 'rgb(0,0,0)',
                                whiteSpace: 'nowrap',
                            }}
                        >
                            {line}
                        </span>
                    ))}
                </div>
            )}
        </div>
    );
}
