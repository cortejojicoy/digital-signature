/**
 * What the signature will actually look like once it is stamped.
 *
 * The placement box does not hold a signature; it holds a signature, a
 * verification QR and a caption, each taking a share of it. Drawing only the
 * ink meant a signatory aligned a box against a form and then got something
 * else printed into it — the caption landing on the form's own name line, the
 * QR squeezing the ink narrower than they expected.
 *
 * Everything here arrives in CSS pixels, already converted by the caller from
 * the PDF-point layout. This component measures nothing and decides nothing:
 * if it did, it would be a second opinion about a layout that has to have
 * exactly one.
 */
export function StampPreview({
    imageUrl,
    imageWidth,
    imageHeight,
    qrSize = 0,
    caption,
}) {
    const align = caption?.align === 'L' ? 'flex-start'
        : caption?.align === 'R' ? 'flex-end'
        : 'center';

    return (
        <div style={{ position: 'absolute', inset: 0, pointerEvents: 'none', userSelect: 'none' }}>
            {imageUrl && (
                <img
                    src={imageUrl}
                    alt=""
                    draggable={false}
                    style={{
                        position: 'absolute',
                        left: 0,
                        top: 0,
                        width:  `${imageWidth}px`,
                        height: `${imageHeight}px`,
                        objectFit: 'contain',
                    }}
                />
            )}

            {qrSize > 0 && (
                <div
                    style={{
                        position: 'absolute',
                        right: 0,
                        top: 0,
                        width:  `${qrSize}px`,
                        height: `${qrSize}px`,
                        // A placeholder, not a rendered code. Its job is to
                        // reserve exactly the space the real one will take;
                        // drawing a scannable code here would invite someone
                        // to scan a signature that does not exist yet.
                        background:
                            'repeating-conic-gradient(rgba(15,23,42,.55) 0% 25%, transparent 0% 50%)'
                            + ` 0 0 / ${Math.max(3, qrSize / 6)}px ${Math.max(3, qrSize / 6)}px`,
                        outline: '1px solid rgba(15,23,42,.45)',
                        outlineOffset: '-1px',
                        borderRadius: '1px',
                        opacity: 0.85,
                    }}
                    title="Verification QR"
                />
            )}

            {caption?.lines?.length > 0 && (
                <div
                    style={{
                        position: 'absolute',
                        left: 0,
                        right: 0,
                        top: `${imageHeight}px`,
                        display: 'flex',
                        flexDirection: 'column',
                        alignItems: align,
                        justifyContent: 'flex-start',
                        overflow: 'hidden',
                    }}
                >
                    {caption.lines.map((line, i) => (
                        <span
                            key={i}
                            style={{
                                fontSize:   `${caption.sizePx}px`,
                                lineHeight: `${caption.lineHeightPx}px`,
                                fontFamily: 'Helvetica, Arial, sans-serif',
                                color: 'rgb(90,90,90)',
                                whiteSpace: 'nowrap',
                                maxWidth: '100%',
                                overflow: 'hidden',
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
