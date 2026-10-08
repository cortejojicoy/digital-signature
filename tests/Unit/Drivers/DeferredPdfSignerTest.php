<?php

use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Contracts\DeferredPdfSigner as DeferredPdfSignerContract;
use Kukux\DigitalSignature\Contracts\DigestSigner;
use Kukux\DigitalSignature\Drivers\PdfSigners\DeferredPdfSigner;
use Kukux\DigitalSignature\Drivers\PdfSigners\PreparedPdf;
use Kukux\DigitalSignature\Drivers\PdfSigners\ReservedSignatureTcpdf;
use Kukux\DigitalSignature\Hub\Cms\SignedDataReader;
use Kukux\DigitalSignature\Tests\Unit\Hub\Cms\CmsTestKeys;

/**
 * Hash-only signing end to end: stamp and reserve (app), sign the digest
 * (hub), inject (app), then verify the finished PDF the way a reader does —
 * from the bytes /ByteRange names and the CMS in /Contents, not from anything
 * the signer told us.
 */
beforeEach(function () {
    Storage::fake('testing');
    Storage::disk('testing')->put('docs/form.pdf', minimalPdf());
    Storage::disk('testing')->put('signatures/ink.png', stampablePng());
});

function prepareForm(?DeferredPdfSigner $signer = null): PreparedPdf
{
    return ($signer ?? app(DeferredPdfSignerContract::class))->prepare(
        pdfPath:   'docs/form.pdf',
        imagePath: 'signatures/ink.png',
        position:  ['page' => 1, 'x' => 100, 'y' => 90, 'width' => 160, 'height' => 50],
        reason:    'Approved',
        caption:   ['Digitally signed', 'by Juan DelaCruz'],
        extraPositions: [['page' => 1, 'x' => 100, 'y' => 400, 'width' => 120, 'height' => 40]],
    );
}

/**
 * What a PDF reader does: read /ByteRange, hash those bytes, decode /Contents.
 *
 * @return array{covered: string, cms: string}
 */
function readSignature(string $pdf): array
{
    $placeholder = DeferredPdfSigner::locatePlaceholder($pdf);
    [$a, $b, $c, $d] = $placeholder['byteRange'];

    $hex = rtrim(substr($pdf, $b + 1, $placeholder['length']), '0');
    // A DER blob can legitimately end in a 0 nibble; re-pad to whole bytes
    // and let the DER length decide where the CMS ends.
    $hex .= strlen($hex) % 2 ? '0' : '';
    $raw = hex2bin($hex);
    $offset = 0;
    $cms = \Kukux\DigitalSignature\Support\Der\Element::readAt($raw."\0\0\0\0", $offset)->encoded;

    return ['covered' => substr($pdf, $a, $b).substr($pdf, $c, $d), 'cms' => $cms];
}

describe('DeferredPdfSigner', function () {

    it('is what the DeferredPdfSigner contract resolves to', function () {
        expect(app(DeferredPdfSignerContract::class))->toBeInstanceOf(DeferredPdfSigner::class);
    });

    it('prepares a stamped PDF with a zero-filled placeholder and its digest', function () {
        $prepared = prepareForm();
        $bytes    = Storage::disk('testing')->get($prepared->path);

        [$a, $b, $c, $d] = $prepared->byteRange;

        expect($prepared->path)->toStartWith('signed-docs/pending/')->toEndWith('.pdf')
            ->and($prepared->placeholderLength)->toBe(DeferredPdfSigner::DEFAULT_PLACEHOLDER_LENGTH)
            ->and($prepared->placeholderLength)->toBeGreaterThanOrEqual(32768)
            ->and($a)->toBe(0)
            ->and($c + $d)->toBe(strlen($bytes))
            ->and(substr($bytes, $b, $c - $b))->toBe('<'.str_repeat('0', $prepared->placeholderLength).'>')
            ->and($prepared->digest)->toBe(hash('sha256', substr($bytes, 0, $b).substr($bytes, $c, $d)))
            // The same document FpdiDriver writes: a certifying signature, DocMDP P=2.
            ->and($bytes)->toContain('/TransformMethod /DocMDP')
            ->and($bytes)->toContain('/P 2')
            ->and($bytes)->toContain('/SubFilter /adbe.pkcs7.detached')
            ->and(preg_match('/\/ByteRange\[0 '.$b.' '.$c.' '.$d.' *\]/', $bytes))->toBe(1);
    });

    foreach (['rsa', 'ec'] as $type) {

        it("round-trips: prepare, sign the digest ({$type}), inject, verify", function () use ($type) {
            $identity = CmsTestKeys::$type();
            $prepared = prepareForm();

            $cms = app(DigestSigner::class)->signDigest($prepared->digest, $identity['cert'], $identity['key'], $identity['chain']);

            $signedPath = app(DeferredPdfSignerContract::class)->inject($prepared->path, $cms);

            $before = Storage::disk('testing')->get($prepared->path);
            $after  = Storage::disk('testing')->get($signedPath);

            expect($signedPath)->not->toBe($prepared->path)
                ->and(strlen($after))->toBe(strlen($before));

            // Only the placeholder changed.
            [, $b, $c] = $prepared->byteRange;
            expect(substr($after, 0, $b + 1))->toBe(substr($before, 0, $b + 1))
                ->and(substr($after, $c - 1))->toBe(substr($before, $c - 1));

            // Verify from the PDF alone.
            $found  = readSignature($after);
            $reader = SignedDataReader::parse($found['cms']);

            expect($found['cms'])->toBe($cms)
                ->and($reader->messageDigest())->toBe(hash('sha256', $found['covered'], true))
                ->and($reader->verifiesWithSignerCertificate())->toBeTrue();

            if (CmsTestKeys::openssl() === null) {
                $this->markTestIncomplete('Pure-PHP verification passed; no openssl binary with `cms` to cross-check.');
            }

            [$ok, $output] = CmsTestKeys::opensslVerify($found['cms'], $found['covered']);
            expect($ok)->toBeTrue($output);
        });
    }

    it('reads the image from another disk', function () {
        Storage::fake('images');
        Storage::disk('images')->put('mirrors/ink.png', stampablePng());
        Storage::disk('testing')->delete('signatures/ink.png');

        $prepared = app(DeferredPdfSignerContract::class)->prepare(
            'docs/form.pdf', 'mirrors/ink.png', ['page' => 1], 'Approved', imageDisk: 'images',
        );

        Storage::disk('testing')->assertExists($prepared->path);
    });

    it('refuses a CMS larger than the placeholder', function () {
        $identity = CmsTestKeys::rsa();
        $signer   = new DeferredPdfSigner(placeholderLength: 1024);
        $prepared = prepareForm($signer);

        expect($prepared->placeholderLength)->toBe(1024);

        $cms = app(DigestSigner::class)->signDigest($prepared->digest, $identity['cert'], $identity['key'], $identity['chain']);

        expect(fn () => $signer->inject($prepared->path, $cms))
            ->toThrow(LengthException::class, 'placeholder holds 1024');
    });

    it('refuses a CMS over a different digest', function () {
        $identity = CmsTestKeys::rsa();
        $prepared = prepareForm();

        $cms = app(DigestSigner::class)->signDigest(hash('sha256', 'another document'), $identity['cert'], $identity['key']);

        expect(fn () => app(DeferredPdfSignerContract::class)->inject($prepared->path, $cms))
            ->toThrow(InvalidArgumentException::class, 'different digest');
    });

    it('refuses garbage instead of a CMS', function () {
        $prepared = prepareForm();

        expect(fn () => app(DeferredPdfSignerContract::class)->inject($prepared->path, 'not a cms'))
            ->toThrow(InvalidArgumentException::class, 'Not a usable CMS');
    });

    it('refuses to inject into a document that is already signed', function () {
        $identity = CmsTestKeys::ec();
        $prepared = prepareForm();
        $cms      = app(DigestSigner::class)->signDigest($prepared->digest, $identity['cert'], $identity['key']);
        $signed   = app(DeferredPdfSignerContract::class)->inject($prepared->path, $cms);

        expect(fn () => app(DeferredPdfSignerContract::class)->inject($signed, $cms))
            ->toThrow(RuntimeException::class, 'already carries a signature');
    });

    it('refuses a placeholder that is not an even, positive size', function () {
        expect(fn () => (new ReservedSignatureTcpdf('P', 'pt'))->reserveSignatureSpace(1001))
            ->toThrow(InvalidArgumentException::class);
    });

    it('never lets TCPDF sign a reserved document with the stand-in key', function () {
        $pdf = (new ReservedSignatureTcpdf('P', 'pt'))->reserveSignature();
        $pdf->AddPage();

        expect(fn () => $pdf->Output('', 'S'))->toThrow(LogicException::class);
    });

    it('fails clearly when there is no placeholder', function () {
        expect(fn () => DeferredPdfSigner::locatePlaceholder(minimalPdf()))
            ->toThrow(RuntimeException::class, 'No signature placeholder');
    });
});
