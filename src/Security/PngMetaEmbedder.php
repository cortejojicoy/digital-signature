<?php

namespace Kukux\DigitalSignature\Security;

/**
 * Low-level PNG chunk reader / writer.
 *
 * Writes two kinds of metadata into every stored signature PNG:
 *
 *   tEXt chunks  — machine-readable security fields (Sig-User-Id, Sig-Machine-Hash,
 *                   Sig-Timestamp, Sig-Hmac).  Used by validateIfPresent() for
 *                   tamper / cross-machine detection.
 *
 *   iTXt chunk   — XMP metadata (keyword "XML:com.adobe.xmp").  This is the format
 *                   that macOS Preview (Tools → Inspector → More Info), Adobe Bridge,
 *                   and ExifTool display.  Contains a human-readable description plus
 *                   all security fields under a custom sig: namespace.
 *
 * Chunk wire format:
 *   [4 bytes BE uint] data length
 *   [4 bytes        ] chunk type (e.g. "tEXt")
 *   [N bytes        ] chunk data
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
     * Inject key→value pairs as tEXt chunks AND an XMP iTXt chunk into $pngBytes.
     *
     * The tEXt chunks are used by this plugin's security pipeline (HMAC verification).
     * The XMP iTXt chunk makes the metadata visible in macOS Preview and ExifTool.
     *
     * Existing chunks with the same keyword are NOT replaced; use read() first
     * if you need to check for conflicts.
     *
     * @throws \InvalidArgumentException if $pngBytes is not a valid PNG.
     */
    public function embed(string $pngBytes, array $meta): string
    {
        $this->assertPng($pngBytes);

        // ── tEXt security chunks ───────────────────────────────────────────────
        $chunks = '';
        foreach ($meta as $keyword => $text) {
            $chunks .= $this->buildChunk('tEXt', $keyword . "\0" . $text);
        }

        // ── XMP iTXt chunk — visible in Preview / ExifTool / Adobe Bridge ──────
        $chunks .= $this->buildXmpChunk($meta);

        // Insert immediately after the 8-byte PNG signature + 25-byte IHDR
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
    // XMP
    // -------------------------------------------------------------------------

    /**
     * Build a PNG iTXt chunk containing XMP metadata derived from $meta.
     *
     * Metadata is written using XMP-spec compliant RDF structures so that it is
     * readable on both platforms without any third-party tools:
     *
     *   macOS  — Preview.app: Tools → Inspector → More Info
     *   Windows — File Explorer: right-click → Properties → Details tab
     *
     * Windows Shell Property System (WIC) requires DC multi-value fields to use
     * RDF container elements (rdf:Alt / rdf:Seq), not plain string literals.
     * macOS Preview accepts both, so this stricter format works everywhere.
     *
     * Windows Details tab mapping:
     *   dc:title       → "Title"
     *   dc:creator     → "Authors"
     *   dc:description → "Comments"
     *   xmp:CreateDate → "Date taken" (some viewers)
     *
     * iTXt chunk data layout (PNG spec §11.3.4.3):
     *   keyword \0 compression_flag(1B) compression_method(1B)
     *   language_tag \0 translated_keyword \0 text
     */
    private function buildXmpChunk(array $meta): string
    {
        $userId      = htmlspecialchars($meta['Sig-User-Id']      ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $signerName  = htmlspecialchars($meta['Sig-Signer-Name']  ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $machineHash = htmlspecialchars($meta['Sig-Machine-Hash'] ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $timestamp   = (int) ($meta['Sig-Timestamp'] ?? 0);
        $hmac        = htmlspecialchars($meta['Sig-Hmac']         ?? '', ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $isoDate     = $timestamp > 0 ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : '';

        // Human-readable one-liner shown by both Preview and Windows Explorer
        $desc = 'Digitally signed image.'
              . ($signerName ? " Signed by: {$signerName}." : ($userId ? " Signer ID: {$userId}." : ''))
              . ($isoDate    ? " Signed at: {$isoDate} UTC." : '');

        // Strip the email portion for dc:creator (Windows "Authors" field expects
        // a plain display name, not "Name <email>")
        $displayName = $signerName
            ? preg_replace('/\s*&lt;[^&]*&gt;\s*$/', '', $signerName)
            : '';

        $xmp = '<?xpacket begin="' . "\xef\xbb\xbf" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n"
             . '<x:xmpmeta xmlns:x="adobe:ns:meta/">' . "\n"
             . '  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' . "\n"
             . '    <rdf:Description rdf:about=""' . "\n"
             . '      xmlns:dc="http://purl.org/dc/elements/1.1/"' . "\n"
             . '      xmlns:xmp="http://ns.adobe.com/xap/1.0/"' . "\n"
             . '      xmlns:sig="http://ns.kukux.io/signature/1.0/">' . "\n"

             // dc:title — Windows "Title" field
             // rdf:Alt + xml:lang="x-default" is required by XMP spec for localizable strings
             . '      <dc:title>' . "\n"
             . '        <rdf:Alt><rdf:li xml:lang="x-default">Digitally Signed Image</rdf:li></rdf:Alt>' . "\n"
             . '      </dc:title>' . "\n"

             // dc:creator — Windows "Authors" field
             // rdf:Seq is required; plain string is silently ignored by Windows WIC
             . '      <dc:creator>' . "\n"
             . '        <rdf:Seq><rdf:li>' . $displayName . '</rdf:li></rdf:Seq>' . "\n"
             . '      </dc:creator>' . "\n"

             // dc:description — Windows "Comments" field + macOS Preview description
             // rdf:Alt + xml:lang="x-default" is the XMP-spec form for multi-language text
             . '      <dc:description>' . "\n"
             . '        <rdf:Alt><rdf:li xml:lang="x-default">' . $desc . '</rdf:li></rdf:Alt>' . "\n"
             . '      </dc:description>' . "\n"

             // xmp:CreateDate — shown as date in many viewers
             . '      <xmp:CreateDate>' . $isoDate . '</xmp:CreateDate>' . "\n"

             // sig:* — custom security namespace; readable via ExifTool on any platform
             . '      <sig:UserId>' . $userId . '</sig:UserId>' . "\n"
             . '      <sig:SignerName>' . $signerName . '</sig:SignerName>' . "\n"
             . '      <sig:MachineHash>' . $machineHash . '</sig:MachineHash>' . "\n"
             . '      <sig:Timestamp>' . $timestamp . '</sig:Timestamp>' . "\n"
             . '      <sig:Hmac>' . $hmac . '</sig:Hmac>' . "\n"

             . '    </rdf:Description>' . "\n"
             . '  </rdf:RDF>' . "\n"
             . '</x:xmpmeta>' . "\n"
             . '<?xpacket end="w"?>';

        // iTXt data: keyword \0 | comp_flag=0 | comp_method=0 | lang \0 | xlated_kw \0 | text
        $data = 'XML:com.adobe.xmp' . "\0\0\0\0\0" . $xmp;

        return $this->buildChunk('iTXt', $data);
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
