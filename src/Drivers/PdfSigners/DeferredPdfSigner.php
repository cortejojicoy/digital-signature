<?php

namespace Kukux\DigitalSignature\Drivers\PdfSigners;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Contracts\DeferredPdfSigner as DeferredPdfSignerContract;
use Kukux\DigitalSignature\Drivers\PdfSigners\Concerns\StampsImportedPages;
use Kukux\DigitalSignature\Hub\Cms\CmsException;
use Kukux\DigitalSignature\Hub\Cms\SignedDataReader;
use Kukux\DigitalSignature\Support\LocalCopy;

/**
 * Hash-only PDF signing (plan phase 0.1 / A7): the app never holds a key.
 *
 *   prepare()  imports and stamps the PDF exactly as FpdiDriver does, writes
 *              the signature dictionary with a zero-filled /Contents and a
 *              real /ByteRange, and returns sha256(bytes the range covers);
 *   — the hub signs that digest (DigestSigner) and returns a detached CMS —
 *   inject()   writes the CMS, hex-encoded, into the placeholder. Not one
 *              other byte changes, so the digest the hub signed is still the
 *              digest of the file.
 *
 * DocMDP P=2 (certifying, form fill and further signatures allowed) is kept,
 * as in FpdiDriver. It does not get in the way of injection: DocMDP governs
 * changes made AFTER the signature, and /Contents is the one region the
 * /ByteRange excludes precisely so the signature can be written into it.
 *
 * Every read goes through LocalCopy, so both the storage disk and the image
 * disk may be S3 (RustFS, plan A12).
 */
class DeferredPdfSigner implements DeferredPdfSignerContract
{
    use StampsImportedPages;

    /**
     * 32 768 hex characters = 16 KiB of CMS: an RSA-4096 signer with a
     * three-certificate chain plus an RFC 3161 token (which carries its own
     * certificates) comes to roughly 8–10 KiB. TCPDF's default of 11 742 is
     * too tight once a timestamp is added. The space costs nothing but file
     * size, and a CMS that does not fit cannot be injected at all.
     */
    public const DEFAULT_PLACEHOLDER_LENGTH = 32768;

    public function __construct(
        protected int $placeholderLength = self::DEFAULT_PLACEHOLDER_LENGTH,
    ) {}

    public function prepare(
        string $pdfPath,
        string $imagePath,
        array $position,
        string $reason,
        array $caption = [],
        array $extraPositions = [],
        ?string $imageDisk = null,
    ): PreparedPdf {
        $diskName = config('signature.storage_disk');

        // One signature, however many appearances.
        $stamps = array_merge([$position], array_values($extraPositions));

        $reserved = LocalCopy::of($diskName, $pdfPath, function (string $inPath) use ($imageDisk, $diskName, $imagePath, $stamps, $caption, $reason): array {
            return LocalCopy::of($imageDisk ?? $diskName, $imagePath, function (string $imageFsPath) use ($inPath, $stamps, $caption, $reason): array {
                $pdf = (new ReservedSignatureTcpdf('P', 'pt'))->reserveSignatureSpace($this->placeholderLength);

                $this->importAndStamp($pdf, $inPath, $imageFsPath, $stamps, $caption);

                $pdf->reserveSignature(2, [
                    'Name'     => config('app.name'),
                    'Reason'   => $reason,
                    'Location' => parse_url((string) config('app.url'), PHP_URL_HOST) ?? '',
                ]);

                return $pdf->outputWithReservedSignature();
            });
        });

        $bytes = $reserved['bytes'];

        // Cross-check TCPDF's layout with the same locator inject() will use,
        // so a prepared file that inject() could not read is caught here.
        $placeholder = self::locatePlaceholder($bytes);

        if ($placeholder['byteRange'] !== $reserved['byteRange']) {
            throw new \RuntimeException('The reserved signature could not be located in the prepared PDF.');
        }

        $path = rtrim((string) config('signature.signed_docs_path', 'signed-docs'), '/')
            .'/pending/'.Str::uuid().'.pdf';

        Storage::disk($diskName)->put($path, $bytes);

        return new PreparedPdf(
            path:              $path,
            digest:            self::digestOf($bytes, $placeholder['byteRange']),
            byteRange:         $placeholder['byteRange'],
            placeholderLength: $placeholder['length'],
        );
    }

    public function inject(string $preparedPath, string $cmsDer): string
    {
        $disk  = Storage::disk(config('signature.storage_disk'));
        $bytes = $disk->get($preparedPath);

        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException("Prepared PDF '{$preparedPath}' was not found.");
        }

        $placeholder = self::locatePlaceholder($bytes);
        [, $gapStart, $gapEnd] = $placeholder['byteRange'];

        if (trim(substr($bytes, $gapStart + 1, $placeholder['length']), '0') !== '') {
            throw new \RuntimeException("'{$preparedPath}' already carries a signature; prepare the document again.");
        }

        $digest = self::digestOf($bytes, $placeholder['byteRange']);

        $this->assertSignsDigest($cmsDer, $digest);

        $hex = bin2hex($cmsDer);

        if (strlen($hex) > $placeholder['length']) {
            throw new \LengthException(sprintf(
                'The CMS needs %d hex characters but the placeholder holds %d. Prepare the document again with a larger placeholder.',
                strlen($hex),
                $placeholder['length'],
            ));
        }

        $signed = substr_replace($bytes, str_pad($hex, $placeholder['length'], '0'), $gapStart + 1, $placeholder['length']);

        // Belt and braces: the only bytes that may differ are inside the gap.
        if (strlen($signed) !== strlen($bytes) || self::digestOf($signed, $placeholder['byteRange']) !== $digest) {
            throw new \RuntimeException('Injecting the CMS changed bytes outside /Contents.');
        }

        $outName = rtrim((string) config('signature.signed_docs_path', 'signed-docs'), '/')
            .'/'.pathinfo($preparedPath, PATHINFO_FILENAME)
            .'_signed_'.time().'_'.Str::random(8).'.pdf';

        // The prepared file is left in place: if storing the result (or the
        // caller's bookkeeping after it) fails, inject() can simply run again.
        // Removing it is the caller's job once the signed copy is recorded.
        $disk->put($outName, $signed);

        return $outName;
    }

    // -------------------------------------------------------------------------

    /**
     * SHA-256 hex of the bytes a /ByteRange covers.
     *
     * @param  array{0:int,1:int,2:int,3:int}  $byteRange
     */
    public static function digestOf(string $bytes, array $byteRange): string
    {
        [$a, $b, $c, $d] = $byteRange;

        return hash('sha256', substr($bytes, $a, $b).substr($bytes, $c, $d));
    }

    /**
     * Find the signature whose /ByteRange describes this file: it starts at
     * 0, its gap is exactly a `<hex>` string, and its second range runs to
     * the last byte. Any other "/ByteRange" (inside an imported page's
     * content, a stale one) fails those checks and is ignored.
     *
     * @return array{byteRange: array{0:int,1:int,2:int,3:int}, length: int}
     */
    public static function locatePlaceholder(string $bytes): array
    {
        preg_match_all('/\/ByteRange\s*\[\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s*\]/', $bytes, $matches, PREG_SET_ORDER);

        $total = strlen($bytes);

        foreach (array_reverse($matches) as $match) {
            [$a, $b, $c, $d] = array_map('intval', array_slice($match, 1, 4));

            $length = $c - $b - 2;

            if ($a !== 0 || $length < 2 || $c + $d !== $total) {
                continue;
            }

            if ($bytes[$b] !== '<' || $bytes[$c - 1] !== '>') {
                continue;
            }

            if (strspn($bytes, '0123456789abcdefABCDEF', $b + 1, $length) !== $length) {
                continue;
            }

            return ['byteRange' => [$a, $b, $c, $d], 'length' => $length];
        }

        throw new \RuntimeException('No signature placeholder (/ByteRange + /Contents) found in the PDF.');
    }

    /**
     * Refuse a CMS that is not a detached SignedData over this document's
     * digest — say, the hub's answer to an earlier prepare() of the same
     * document. Injected anyway, it would produce a PDF every reader rejects.
     */
    protected function assertSignsDigest(string $cmsDer, string $digestHex): void
    {
        try {
            $messageDigest = SignedDataReader::parse($cmsDer)->messageDigest();
        } catch (CmsException $e) {
            throw new \InvalidArgumentException('Not a usable CMS signature: '.$e->getMessage(), 0, $e);
        }

        if ($messageDigest === null || ! hash_equals($digestHex, bin2hex($messageDigest))) {
            throw new \InvalidArgumentException('The CMS signs a different digest than this prepared PDF\'s /ByteRange.');
        }
    }
}
