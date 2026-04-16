<?php

namespace Kukux\DigitalSignature\Security;

/**
 * Low-level PNG tEXt chunk reader / writer.
 *
 * PNG files store metadata as typed chunks. A tEXt chunk holds a null-terminated
 * keyword followed by a Latin-1 text value.  We inject our metadata chunks
 * immediately after the IHDR chunk (the mandatory first chunk after the PNG
 * signature), which is the conventional position for metadata.
 *
 * Chunk wire format:
 *   [4 bytes BE uint] data length
 *   [4 bytes        ] chunk type (e.g. "tEXt")
 *   [N bytes        ] keyword + 0x00 + text
 *   [4 bytes BE uint] CRC-32 of (type + data)
 *
 * Reference: http://www.libpng.org/pub/png/spec/1.2/PNG-Chunks.html
 */
class PngMetaEmbedder
{
    private const PNG_SIG  = "\x89PNG\r\n\x1a\n";
    private const IHDR_LEN = 25;   // 4 (len) + 4 (type) + 13 (data) + 4 (CRC)

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Inject key→value pairs as tEXt chunks into $pngBytes.
     * Existing chunks with the same keyword are NOT replaced; use read() first
     * if you need to check for conflicts.
     *
     * @throws \InvalidArgumentException if $pngBytes is not a valid PNG.
     */
    public function embed(string $pngBytes, array $meta): string
    {
        $this->assertPng($pngBytes);

        $chunks = '';
        foreach ($meta as $keyword => $text) {
            $chunks .= $this->buildChunk('tEXt', $keyword . "\0" . $text);
        }

        // Insert after the 8-byte signature + 25-byte IHDR
        $insertAt = strlen(self::PNG_SIG) + self::IHDR_LEN;

        return substr($pngBytes, 0, $insertAt)
             . $chunks
             . substr($pngBytes, $insertAt);
    }

    /**
     * Read all tEXt chunk key→value pairs from $pngBytes.
     * Returns an empty array if the file is not a valid PNG or has no tEXt chunks.
     */
    public function read(string $pngBytes): array
    {
        if (!$this->isPng($pngBytes)) {
            return [];
        }

        $meta   = [];
        $offset = strlen(self::PNG_SIG);
        $total  = strlen($pngBytes);

        while ($offset + 12 <= $total) {
            $len  = unpack('N', substr($pngBytes, $offset, 4))[1];
            $type = substr($pngBytes, $offset + 4, 4);
            $data = substr($pngBytes, $offset + 8, $len);

            if ($type === 'tEXt') {
                $null = strpos($data, "\0");
                if ($null !== false) {
                    $meta[substr($data, 0, $null)] = substr($data, $null + 1);
                }
            }

            if ($type === 'IEND') {
                break;
            }

            $offset += 4 + 4 + $len + 4; // len-field + type + data + CRC
        }

        return $meta;
    }

    /**
     * Return true if $bytes starts with the PNG magic bytes.
     */
    public function isPng(string $bytes): bool
    {
        return str_starts_with($bytes, self::PNG_SIG);
    }

    /**
     * Convert JPEG bytes to PNG bytes using GD.
     * Returns $bytes unchanged if the input is already a PNG.
     *
     * @throws \RuntimeException if GD cannot decode the image.
     */
    public function normalizeToPng(string $bytes): string
    {
        if ($this->isPng($bytes)) {
            return $bytes;
        }

        $img = @imagecreatefromstring($bytes);

        if ($img === false) {
            throw new \RuntimeException('PngMetaEmbedder: cannot decode image (unsupported format or corrupt data).');
        }

        // Preserve transparency for GIF/PNG sources that end up here
        imagesavealpha($img, true);

        ob_start();
        imagepng($img, null, 6); // compression 6 — balance size vs speed
        $png = ob_get_clean();
        imagedestroy($img);

        return $png;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private function buildChunk(string $type, string $data): string
    {
        $len = strlen($data);
        $crc = crc32($type . $data);

        return pack('N', $len)   // data length (unsigned 32-bit BE)
             . $type             // 4-byte chunk type
             . $data             // chunk data
             . pack('N', $crc); // CRC (pack('N', signed) gives correct 32-bit BE)
    }

    private function assertPng(string $bytes): void
    {
        if (!$this->isPng($bytes)) {
            throw new \InvalidArgumentException(
                'PngMetaEmbedder: input is not a PNG file (missing magic bytes).'
            );
        }
    }
}
