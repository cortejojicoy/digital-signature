<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Contracts\DeferredPdfSigner as DeferredPdfSignerContract;
use Kukux\DigitalSignature\Drivers\PdfSigners\FpdiDriver;
use Kukux\DigitalSignature\Drivers\PdfSigners\TcpdfDriver;
use Kukux\DigitalSignature\Support\LocalCopy;
use Kukux\DigitalSignature\Support\LocalCopyIntegrityException;
use League\Flysystem\DecoratedAdapter;
use League\Flysystem\Filesystem as Flysystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * LocalCopy, against a disk that behaves like S3 (RustFS): files readable
 * through the Storage API, but no real path — path() here throws outright,
 * so anything that still calls it fails loudly instead of reading the wrong
 * file. Optionally it answers headObject() like the AWS client, for the
 * x-amz-meta-sha256 check.
 */
function remoteDisk(string $name, ?array $metadata = null): FilesystemAdapter
{
    $root    = storage_path("framework/testing/disks/{$name}");
    \Illuminate\Support\Facades\File::deleteDirectory($root);
    $adapter = new class(new LocalFilesystemAdapter($root)) extends DecoratedAdapter {};

    $disk = new class(new Flysystem($adapter), $adapter, ['root' => $root, 'bucket' => 'signature-mirrors']) extends FilesystemAdapter
    {
        public ?array $metadata = null;

        public array $heads = [];

        public function path($path)
        {
            throw new RuntimeException('This disk has no local paths.');
        }

        public function getClient(): object
        {
            return new class($this)
            {
                public function __construct(private $disk) {}

                public function headObject(array $args): array
                {
                    $this->disk->heads[] = $args;

                    if ($this->disk->metadata === null) {
                        throw new RuntimeException('404');
                    }

                    return ['Metadata' => $this->disk->metadata];
                }
            };
        }
    };

    $disk->metadata = $metadata;

    Storage::set($name, $disk);

    return $disk;
}

/** Temp files LocalCopy may have left behind. */
function sigcopyTemps(): array
{
    return glob(sys_get_temp_dir().'/sigcopy*') ?: [];
}

describe('LocalCopy', function () {

    beforeEach(function () {
        Storage::fake('testing');
    });

    it('hands over the real path on a local disk', function () {
        Storage::disk('testing')->put('a/ink.png', 'bytes');

        $seen = LocalCopy::of('testing', 'a/ink.png', fn (string $path) => $path);

        expect($seen)->toBe(Storage::disk('testing')->path('a/ink.png'))
            ->and(LocalCopy::isLocal(Storage::disk('testing')))->toBeTrue();
    });

    it('downloads from a non-local disk to a temp file and deletes it afterwards', function () {
        $disk = remoteDisk('remote');
        $disk->put('app-1/ink.png', 'remote bytes');
        $before = sigcopyTemps();

        $seen = LocalCopy::of('remote', 'app-1/ink.png', function (string $path) {
            expect(file_get_contents($path))->toBe('remote bytes')
                ->and($path)->toEndWith('.png');

            return $path;
        });

        expect(LocalCopy::isLocal($disk))->toBeFalse()
            ->and(file_exists($seen))->toBeFalse()
            ->and(sigcopyTemps())->toBe($before);
    });

    it('deletes the temp file when the callback throws', function () {
        remoteDisk('remote')->put('ink.png', 'x');
        $before = sigcopyTemps();

        expect(fn () => LocalCopy::of('remote', 'ink.png', fn () => throw new LogicException('boom')))
            ->toThrow(LogicException::class, 'boom')
            ->and(sigcopyTemps())->toBe($before);
    });

    it('checks an expected SHA-256 on both kinds of disk', function () {
        Storage::disk('testing')->put('ink.png', 'genuine');
        remoteDisk('remote')->put('ink.png', 'genuine');

        $good = hash('sha256', 'genuine');

        expect(LocalCopy::of('testing', 'ink.png', fn () => 'ok', $good))->toBe('ok')
            ->and(LocalCopy::of('remote', 'ink.png', fn () => 'ok', strtoupper($good)))->toBe('ok');

        foreach (['testing', 'remote'] as $diskName) {
            $called = false;

            try {
                LocalCopy::of($diskName, 'ink.png', function () use (&$called) {
                    $called = true;
                }, hash('sha256', 'edited'));

                $this->fail('A mismatched file was handed over.');
            } catch (LocalCopyIntegrityException $e) {
                expect($called)->toBeFalse()
                    ->and($e->path)->toBe('ink.png')
                    ->and($e->expectedSha256)->toBe(hash('sha256', 'edited'))
                    ->and($e->actualSha256)->toBe($good);
            }
        }
    });

    it('checks x-amz-meta-sha256 when no hash is given', function () {
        $disk = remoteDisk('remote', ['sha256' => hash('sha256', 'as uploaded')]);
        $disk->put('mirrors/ink.png', 'edited inside the bucket');

        expect(fn () => LocalCopy::of('remote', 'mirrors/ink.png', fn () => 'stamped'))
            ->toThrow(LocalCopyIntegrityException::class);

        expect($disk->heads[0]['Bucket'])->toBe('signature-mirrors')
            ->and($disk->heads[0]['Key'])->toEndWith('mirrors/ink.png');

        $disk->metadata = ['sha256' => hash('sha256', 'edited inside the bucket')];
        expect(LocalCopy::of('remote', 'mirrors/ink.png', fn () => 'stamped'))->toBe('stamped');

        // No metadata on the object is not tampering.
        $disk->metadata = null;
        expect(LocalCopy::of('remote', 'mirrors/ink.png', fn () => 'stamped'))->toBe('stamped');
    });

    it('reports a missing file clearly', function () {
        remoteDisk('remote');

        expect(fn () => LocalCopy::of('remote', 'nope.png', fn () => null))->toThrow(RuntimeException::class, "Could not read 'nope.png'")
            ->and(fn () => LocalCopy::of('testing', 'nope.png', fn () => null, str_repeat('0', 64)))
            ->toThrow(LocalCopyIntegrityException::class, 'missing');
    });
});

describe('drivers on a non-local disk', function () {

    beforeEach(function () {
        // The whole signature disk behaves like S3: the source PDF, the image
        // and the output all go through the Storage API.
        $disk = remoteDisk('remote-signatures');
        $disk->put('docs/form.pdf', minimalPdf());
        $disk->put('signatures/ink.png', stampablePng());

        config()->set('signature.storage_disk', 'remote-signatures');
    });

    it('FpdiDriver stamps and signs without a local path', function () {
        $out = (new FpdiDriver)->sign('docs/form.pdf', 'signatures/ink.png', ['page' => 1, 'x' => 40, 'y' => 700], [], caption: ['by Juan DelaCruz']);

        expect(Storage::disk('remote-signatures')->get($out))->toStartWith('%PDF-');
    });

    it('TcpdfDriver stamps without a local path', function () {
        $out = (new TcpdfDriver)->sign('docs/form.pdf', 'signatures/ink.png', ['x' => 40, 'y' => 200], []);

        expect(Storage::disk('remote-signatures')->get($out))->toStartWith('%PDF-');
    });

    it('DeferredPdfSigner prepares and injects without a local path', function () {
        $signer   = app(DeferredPdfSignerContract::class);
        $prepared = $signer->prepare('docs/form.pdf', 'signatures/ink.png', ['page' => 1], 'Approved');

        $identity = \Kukux\DigitalSignature\Tests\Unit\Hub\Cms\CmsTestKeys::ec();
        $cms      = app(\Kukux\DigitalSignature\Contracts\DigestSigner::class)
            ->signDigest($prepared->digest, $identity['cert'], $identity['key']);

        $signed = $signer->inject($prepared->path, $cms);

        expect(Storage::disk('remote-signatures')->get($signed))->toContain(bin2hex($cms));
    });
});
