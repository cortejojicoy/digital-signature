<?php

namespace Kukux\DigitalSignature\Support;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Local\LocalFilesystemAdapter;

/**
 * A filesystem path for a file on any disk, for exactly as long as a callback
 * runs.
 *
 * TCPDF, FPDI and getimagesize() read from a path; an S3 disk (RustFS, plan
 * A12) has none — `Storage::disk('s3')->path()` does not throw, it quietly
 * returns the object key, which then "does not exist". So:
 *
 *   - a local disk hands over its real path, unchanged from what the drivers
 *     always did;
 *   - any other disk is downloaded to a temp file, the callback runs, and the
 *     temp file is deleted in `finally` — signature images never stay on an
 *     app server's disk at rest.
 *
 * Integrity (plan R5): pass the SHA-256 the file is supposed to have (a
 * mirror's `hub_image_hash`) and the copy is checked before the callback
 * sees it. Without one, an object stored with `x-amz-meta-sha256` metadata is
 * checked against that. A mismatch throws {@see LocalCopyIntegrityException}
 * — a stamp is never drawn from a file that changed under us.
 */
final class LocalCopy
{
    /**
     * @template T
     *
     * @param  string|Filesystem  $disk  Disk name or instance.
     * @param  callable(string $localPath): T  $callback
     * @param  string|null  $expectedSha256  Hex SHA-256 the file must have.
     * @return T
     */
    public static function of(string|Filesystem $disk, string $path, callable $callback, ?string $expectedSha256 = null): mixed
    {
        $filesystem = is_string($disk) ? Storage::disk($disk) : $disk;

        if (self::isLocal($filesystem)) {
            $local = $filesystem->path($path);

            if ($expectedSha256 !== null) {
                self::verify($local, $expectedSha256, $path);
            }

            return $callback($local);
        }

        $temp = self::download($filesystem, $path);

        try {
            self::verify($temp, $expectedSha256 ?? self::storedSha256($filesystem, $path), $path);

            return $callback($temp);
        } finally {
            @unlink($temp);
        }
    }

    /**
     * Whether the disk's files are plain files on this machine. Decided by
     * the adapter rather than by trying path(), which "works" on S3 too.
     */
    public static function isLocal(Filesystem $filesystem): bool
    {
        return $filesystem instanceof FilesystemAdapter
            && $filesystem->getAdapter() instanceof LocalFilesystemAdapter;
    }

    // -------------------------------------------------------------------------

    private static function download(Filesystem $filesystem, string $path): string
    {
        $stream = $filesystem->readStream($path);

        if (! is_resource($stream)) {
            throw new \RuntimeException("Could not read '{$path}' from its disk.");
        }

        $temp = tempnam(sys_get_temp_dir(), 'sigcopy');

        if ($temp === false) {
            fclose($stream);

            throw new \RuntimeException('Could not create a temporary file.');
        }

        // Keep the extension: some readers (TCPDF's Image()) sniff the type
        // from it when they are not told.
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        if ($extension !== '' && rename($temp, $temp.'.'.$extension)) {
            $temp .= '.'.$extension;
        }

        try {
            $out = fopen($temp, 'wb');

            if ($out === false || stream_copy_to_stream($stream, $out) === false) {
                throw new \RuntimeException("Could not copy '{$path}' to a temporary file.");
            }

            fclose($out);
        } catch (\Throwable $e) {
            @unlink($temp);

            throw $e;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return $temp;
    }

    /**
     * The `x-amz-meta-sha256` the hub writes on every object (plan A12), or
     * null when the disk is not S3 or the object has none.
     */
    private static function storedSha256(Filesystem $filesystem, string $path): ?string
    {
        if (! method_exists($filesystem, 'getClient') || ! method_exists($filesystem, 'getConfig')) {
            return null;
        }

        try {
            $config = $filesystem->getConfig();
            $prefix = trim((string) ($config['root'] ?? $config['prefix'] ?? ''), '/');

            $head = $filesystem->getClient()->headObject([
                'Bucket' => $config['bucket'] ?? null,
                'Key'    => ltrim(($prefix !== '' ? $prefix.'/' : '').ltrim($path, '/'), '/'),
            ]);
        } catch (\Throwable) {
            // No metadata is not tampering; the download itself succeeded.
            return null;
        }

        $metadata = array_change_key_case((array) ($head['Metadata'] ?? []), CASE_LOWER);
        $hash     = $metadata['sha256'] ?? null;

        return is_string($hash) && $hash !== '' ? $hash : null;
    }

    private static function verify(string $localPath, ?string $expectedSha256, string $path): void
    {
        if ($expectedSha256 === null) {
            return;
        }

        $actual = is_file($localPath) ? hash_file('sha256', $localPath) : false;

        if ($actual === false) {
            throw new LocalCopyIntegrityException($path, strtolower($expectedSha256), null);
        }

        if (! hash_equals(strtolower($expectedSha256), $actual)) {
            throw new LocalCopyIntegrityException($path, strtolower($expectedSha256), $actual);
        }
    }
}
