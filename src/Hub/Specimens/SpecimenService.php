<?php

namespace Kukux\DigitalSignature\Hub\Specimens;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Hub\Api\People;
use Kukux\DigitalSignature\Hub\Webhooks\HubNotifier;
use Kukux\DigitalSignature\Models\Signature;

/**
 * A person's master specimen at the hub: the primary signature image apps
 * mirror and stamp (A12).
 *
 * The profile page stores a signature the usual way (SignatureManager::store)
 * and then calls published(), which puts the image on the specimen disk
 * (RustFS `signature-hub`, versioned), records its object version, and tells
 * the apps that hold a mirror to re-pull.
 */
class SpecimenService
{
    public function __construct(
        private readonly People $people,
        private readonly HubNotifier $notifier,
    ) {}

    /** The person's active primary signature on this account, if any. */
    public function current(int $userId): ?Signature
    {
        return Signature::query()
            ->primaryActiveFor($userId)
            ->where('status', 'active')
            ->where('source', '!=', 'hub')
            ->latest('id')
            ->first();
    }

    /** Master images: `hub.specimen_disk`, else the package's storage disk. */
    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    public function diskName(): string
    {
        return (string) (config('signature.hub.specimen_disk') ?: config('signature.storage_disk'));
    }

    /**
     * The image bytes, from the specimen disk, falling back to the storage
     * disk for a signature stored before a specimen disk was configured.
     */
    public function read(Signature $signature): ?string
    {
        foreach (array_unique([$this->diskName(), (string) config('signature.storage_disk')]) as $name) {
            $disk = Storage::disk($name);

            if ($disk->exists($signature->image_path)) {
                return $disk->get($signature->image_path);
            }
        }

        return null;
    }

    /**
     * Call after a person stores or replaces their signature at the hub.
     *
     *   1. Copy the image to the specimen disk when that is not the storage
     *      disk, with `x-amz-meta-sha256` so a reader can check it (R5).
     *   2. Record the object's version id when the disk is versioned S3
     *      (best effort: a missing version id never fails a signature).
     *   3. Notify holder apps: `signature.updated`.
     */
    public function published(Signature $signature): void
    {
        $bytes = Storage::disk(config('signature.storage_disk'))->get($signature->image_path);

        if ($bytes === null) {
            Log::warning('signature-hub: published() could not read the signature image.', ['signature' => $signature->uuid]);

            return;
        }

        $sha256 = hash('sha256', $bytes);
        $disk = $this->disk();

        // Copy when the specimen disk is another disk; rewrite in place on S3
        // so the object carries its hash in metadata.
        if ($this->diskName() !== (string) config('signature.storage_disk') || $disk instanceof AwsS3V3Adapter) {
            $disk->put($signature->image_path, $bytes, [
                'visibility'  => 'private',
                'ContentType' => 'image/png',
                'Metadata'    => ['sha256' => $sha256],
            ]);
        }

        $versionId = $this->versionId($disk, $signature->image_path);

        if ($versionId !== null) {
            $signature->forceFill(['hub_version_id' => $versionId])->save();
        }

        $key = $this->people->personnelKeyFor((int) $signature->user_id);

        if ($key !== null) {
            $this->notifier->signatureUpdated($key);
        }
    }

    /** The S3 VersionId of an object, or null (not S3, unversioned, or unreachable). */
    public function versionId(Filesystem $disk, string $path): ?string
    {
        if (! $disk instanceof AwsS3V3Adapter) {
            return null;
        }

        try {
            $head = $disk->getClient()->headObject([
                'Bucket' => $disk->getConfig()['bucket'] ?? null,
                'Key'    => $disk->path($path),
            ]);

            $version = $head['VersionId'] ?? null;

            return is_string($version) && $version !== '' && $version !== 'null' ? $version : null;
        } catch (\Throwable $e) {
            Log::info('signature-hub: could not read the specimen version id.', ['path' => $path, 'error' => $e->getMessage()]);

            return null;
        }
    }
}
