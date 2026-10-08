<?php

namespace Kukux\DigitalSignature\Client;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Models\Signature;

/**
 * Where a hub mirror's image lives, and how it is written and read back.
 *
 *   hub.mirror_disk set   that disk; 'rustfs' (an s3 disk on
 *                         rustfs.uplb.edu.ph) keeps images off this server
 *   hub.mirror_disk null  signature.storage_disk
 *
 * On a local disk the image goes under `hub.mirror_dir`; on an object store
 * the disk's own root is the app's prefix (`{app_id}/`, A12), so the object
 * is just `{hub_uuid}.png` there.
 *
 * Everything that reads a signature image asks diskNameFor() rather than
 * assuming signature.storage_disk: the asset route, the hash-only stamp, and
 * document rows copied from a mirror (they keep `source = hub` and the same
 * image path). The disk a mirror was written to is also recorded on the row
 * (`pades_info.mirror_disk`), so changing SIGNATURE_HUB_MIRROR_DISK later
 * doesn't strand the images already written.
 */
final class MirrorStorage
{
    /** The disk new mirrors are written to. */
    public static function diskName(): string
    {
        return (string) (config('signature.hub.mirror_disk') ?: config('signature.storage_disk'));
    }

    /** The disk this signature's image is on. */
    public static function diskNameFor(Signature $signature): string
    {
        if ($signature->source !== 'hub') {
            return (string) config('signature.storage_disk');
        }

        $recorded = is_array($signature->pades_info) ? ($signature->pades_info['mirror_disk'] ?? null) : null;

        return is_string($recorded) && $recorded !== '' ? $recorded : self::diskName();
    }

    public static function disk(?string $name = null): Filesystem
    {
        return Storage::disk($name ?? self::diskName());
    }

    /** An S3-compatible store (RustFS) rather than a folder on this server. */
    public static function isObjectStore(?string $name = null): bool
    {
        return config('filesystems.disks.'.($name ?? self::diskName()).'.driver') === 's3';
    }

    public static function pathFor(string $hubUuid): string
    {
        if (self::isObjectStore()) {
            return "{$hubUuid}.png";
        }

        return trim((string) config('signature.hub.mirror_dir', 'signatures/hub'), '/')."/{$hubUuid}.png";
    }

    /**
     * Write an image, private, with its hash as object metadata where the
     * disk supports it (`x-amz-meta-sha256` on RustFS).
     */
    public static function put(string $path, string $bytes, string $sha256): void
    {
        $options = self::isObjectStore()
            ? ['visibility' => 'private', 'Metadata' => ['sha256' => $sha256], 'ContentType' => 'image/png']
            : [];

        self::disk()->put($path, $bytes, $options);
    }

    /** The image bytes, or null when the file is gone. */
    public static function read(Signature $signature): ?string
    {
        $disk = self::disk(self::diskNameFor($signature));

        if (! $signature->image_path || ! $disk->exists($signature->image_path)) {
            return null;
        }

        $bytes = $disk->get($signature->image_path);

        return is_string($bytes) ? $bytes : null;
    }

    public static function exists(Signature $signature): bool
    {
        return $signature->image_path !== null
            && self::disk(self::diskNameFor($signature))->exists($signature->image_path);
    }

    public static function delete(Signature $signature): void
    {
        if ($signature->image_path) {
            self::disk(self::diskNameFor($signature))->delete($signature->image_path);
        }
    }
}
