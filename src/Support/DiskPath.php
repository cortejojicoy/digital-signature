<?php

namespace Kukux\DigitalSignature\Support;

use Illuminate\Support\Facades\Storage;

/**
 * Normalizes between the two path conventions this package has to live with.
 *
 * `Signable::getSignablePdfPath()` and `PdfTemplate::renderFor()` are
 * documented as returning an ABSOLUTE filesystem path, while the signer
 * drivers and every stored `*_path` column are disk-RELATIVE and get run
 * through `Storage::disk()->path()`. Handing an absolute path to `path()`
 * produces `<root>/<abs path>`, which silently points at nothing.
 *
 * Session code passes documents between those two worlds on every signature,
 * so it converts explicitly here rather than hoping each caller picked the
 * right convention.
 */
final class DiskPath
{
    /**
     * Disk-relative form of a path that may already be relative or may be an
     * absolute path inside the disk root. An absolute path outside the root
     * is returned unchanged — the caller is reaching outside the disk on
     * purpose and normalizing would corrupt it.
     */
    public static function relative(string $path): string
    {
        $root = self::root();

        if ($root === null || ! str_starts_with($path, $root)) {
            return $path;
        }

        return ltrim(substr($path, strlen($root)), '/\\');
    }

    /**
     * Absolute form of a path that may already be absolute.
     */
    public static function absolute(string $path): string
    {
        if (self::isAbsolute($path)) {
            return $path;
        }

        return Storage::disk(config('signature.storage_disk'))->path($path);
    }

    public static function isAbsolute(string $path): bool
    {
        return str_starts_with($path, '/')
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    }

    /**
     * Root directory of the signature disk, or null for drivers that have
     * no local root (s3 and friends), where every path is already relative.
     */
    private static function root(): ?string
    {
        $disk = Storage::disk(config('signature.storage_disk'));

        try {
            $root = $disk->path('');
        } catch (\Throwable) {
            return null;
        }

        $root = rtrim((string) $root, '/\\');

        return $root === '' ? null : $root;
    }
}
