<?php

namespace Kukux\DigitalSignature\Client;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Client\Exceptions\HubException;
use Kukux\DigitalSignature\Client\Exceptions\HubNotFoundException;
use Kukux\DigitalSignature\Client\Exceptions\MirrorIntegrityException;
use Kukux\DigitalSignature\Models\HubAccount;
use Kukux\DigitalSignature\Models\HubPendingSign;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureAudit;

/**
 * The only writer of mirror rows (A4).
 *
 * A mirror is an ordinary `digital_signatures` row (`signable_id` null,
 * `status` active, `source` hub) whose image is a read-only copy of the
 * person's signature at the hub. The drag tray, the launcher and routing all
 * read it like any primary signature. It holds no certificate password: in
 * client mode this app has no keys.
 *
 * One mirror per person. A changed specimen updates the row in place (new
 * image, new hashes), so a signature the viewer already has open keeps
 * working; the old image file is deleted once the new one is written.
 * Revoking marks the row revoked and deletes the image. Rows copied onto
 * documents (`storeForDocument()`) are never touched.
 *
 * Called from: first hub sign-in, the `signature.updated` webhook, a
 * `409 specimen_changed`, the viewer when the mirror is older than
 * `hub.stale_after`, and `signature:hub-sync`.
 */
class HubSignatureSync
{
    public function __construct(protected HubClient $hub)
    {
    }

    /** The person's current mirror, if any. */
    public function mirrorFor(int $userId): ?Signature
    {
        return Signature::query()
            ->where('user_id', $userId)
            ->whereNull('signable_id')
            ->where('source', 'hub')
            ->where('status', 'active')
            ->latest('id')
            ->first();
    }

    /**
     * Bring the user's mirror in line with the hub.
     *
     * Order matters: the image is fetched and checked, written, and only then
     * is the row pointed at it. A failure part way leaves the previous mirror
     * exactly as it was.
     *
     * @return Signature|null  the mirror, or null when the hub has none (or
     *                         the user was never linked to a hub person)
     *
     * @throws HubException when the hub can't be asked
     * @throws MirrorIntegrityException when the image doesn't match its hash
     */
    public function pull(Authenticatable|int $user): ?Signature
    {
        $userId = (int) ($user instanceof Authenticatable ? $user->getAuthIdentifier() : $user);
        $sub = HubAccount::subFor($userId);

        if ($sub === null) {
            return null;
        }

        return Cache::lock("signature:hub:pull:{$userId}", 30)->block(10, function () use ($userId, $sub) {
            return $this->pullLocked($userId, $sub);
        });
    }

    protected function pullLocked(int $userId, string $sub): ?Signature
    {
        $mirror = $this->mirrorFor($userId);

        try {
            $meta = $this->hub->signature($sub);
        } catch (HubNotFoundException) {
            $this->clear($userId, 'no_signature');

            return null;
        }

        if (($meta['status'] ?? 'active') !== 'active' || empty($meta['uuid']) || empty($meta['image_sha256'])) {
            $this->clear($userId, (string) ($meta['status'] ?? 'inactive'));

            return null;
        }

        $hubUuid = (string) $meta['uuid'];
        $expected = strtolower((string) $meta['image_sha256']);

        // Ask for the image only if it changed, unless the file we hold has
        // gone missing or the specimen moved to a new uuid (a new file name).
        $current = $mirror !== null
            && $mirror->hub_uuid === $hubUuid
            && MirrorStorage::exists($mirror);

        $image = $this->hub->image($sub, $current ? $mirror->hub_image_hash : null);

        if ($image === null) {
            // 304: same image as ours. Only the check time moves.
            $mirror->update(['hub_synced_at' => now()]);

            return $mirror;
        }

        $actual = hash('sha256', $image['bytes']);

        if (! hash_equals($expected, $actual) || ($image['sha256'] !== null && ! hash_equals($image['sha256'], $actual))) {
            Log::warning('mirror.hash_mismatch', [
                'user_id'  => $userId,
                'sub'      => $sub,
                'expected' => $expected,
                'header'   => $image['sha256'],
                'actual'   => $actual,
            ]);

            throw new MirrorIntegrityException(
                'The signature image from the hub does not match its hash, so it was not saved.'
            );
        }

        $path = MirrorStorage::pathFor($hubUuid);

        MirrorStorage::put($path, $image['bytes'], $actual);

        $attributes = [
            'image_path'     => $path,
            'image_hash'     => hash((string) config('signature.hash_algo', 'sha256'), $image['bytes']),
            'hub_uuid'       => $hubUuid,
            'hub_image_hash' => $actual,
            'hub_synced_at'  => now(),
            'pades_info'     => ['mirror_disk' => MirrorStorage::diskName()],
        ];

        if ($mirror === null) {
            return Signature::create($attributes + [
                'uuid'                 => (string) Str::uuid(),
                'user_id'              => $userId,
                'source'               => 'hub',
                'status'               => 'active',
                'certificate_password' => null,
            ]);
        }

        $previous = clone $mirror;

        $mirror->update($attributes);

        // The old file only after the row points at the new one.
        if ($previous->image_path && $previous->image_path !== $path) {
            MirrorStorage::delete($previous);
        }

        return $mirror;
    }

    /**
     * The hub revoked this person's signature, or they separated: delete the
     * image, mark the mirror revoked, and stop anything waiting to sign with
     * it.
     *
     * @return int  mirrors revoked
     */
    public function revoke(string $sub, ?string $hubUuid = null, string $reason = 'revoked'): int
    {
        $userId = HubAccount::userIdFor($sub);

        if ($userId === null && ($hubUuid === null || $hubUuid === '')) {
            return 0;
        }

        $mirrors = Signature::query()
            ->whereNull('signable_id')
            ->where('source', 'hub')
            ->where('status', '!=', 'revoked')
            ->where(function ($query) use ($userId, $hubUuid) {
                if ($userId !== null) {
                    $query->orWhere('user_id', $userId);
                }

                if ($hubUuid !== null && $hubUuid !== '') {
                    $query->orWhere('hub_uuid', $hubUuid);
                }
            })
            ->get();

        foreach ($mirrors as $mirror) {
            $this->retire($mirror, $reason);
        }

        foreach ($mirrors->pluck('user_id')->push($userId)->filter()->unique() as $owner) {
            $this->blockPending((int) $owner, $reason);
        }

        return $mirrors->count();
    }

    /**
     * Refuse every hub signature this user is still waiting on, so a CMS
     * that arrives after a revocation is never put on a document.
     *
     * @return int  requests blocked
     */
    public function blockPending(int $userId, string $reason): int
    {
        return HubPendingSign::query()
            ->where('user_id', $userId)
            ->whereIn('status', ['unsent', 'pending', 'approved', 'signed'])
            ->update(['status' => 'refused', 'reason' => Str::limit($reason, 60, ''), 'updated_at' => now()]);
    }

    /**
     * Re-check a mirror the viewer is about to use, when it is older than
     * `hub.stale_after` (a missed webhook, R6). Never throws: an unreachable
     * hub leaves the mirror as it is.
     */
    public function staleCheck(Authenticatable|int $user): ?Signature
    {
        $userId = (int) ($user instanceof Authenticatable ? $user->getAuthIdentifier() : $user);
        $mirror = $this->mirrorFor($userId);
        $staleAfter = max(0, (int) config('signature.hub.stale_after', 86400));

        $fresh = $mirror?->hub_synced_at !== null
            && $mirror->hub_synced_at->gt(now()->subSeconds($staleAfter));

        // A person with no mirror is asked about at most once per window,
        // or every viewer open would be a hub call.
        $checkedKey = "signature:hub:stale-check:{$userId}";

        if ($fresh || ($mirror === null && Cache::has($checkedKey)) || HubAccount::subFor($userId) === null) {
            return $mirror;
        }

        Cache::put($checkedKey, true, max(60, min($staleAfter, 900)));

        try {
            return $this->pull($userId);
        } catch (HubException|MirrorIntegrityException $e) {
            Log::info('Hub mirror re-check skipped: '.$e->getMessage(), ['user_id' => $userId]);

            return $mirror;
        }
    }

    /**
     * Read a mirror's image back and check it still hashes to what the hub
     * gave us (R5): an image edited on the disk is never stamped. On a
     * mismatch the file is replaced from the hub once.
     *
     * @throws MirrorIntegrityException when it still doesn't match
     */
    public function verified(Signature $mirror): Signature
    {
        $bytes = MirrorStorage::read($mirror);

        if ($bytes !== null && $mirror->hub_image_hash !== null && hash_equals($mirror->hub_image_hash, hash('sha256', $bytes))) {
            return $mirror;
        }

        Log::warning('mirror.tampered', [
            'user_id'   => $mirror->user_id,
            'signature' => $mirror->uuid,
            'path'      => $mirror->image_path,
            'missing'   => $bytes === null,
        ]);

        MirrorStorage::delete($mirror);

        $fresh = $this->pull((int) $mirror->user_id);

        if ($fresh === null || ($bytes = MirrorStorage::read($fresh)) === null
            || ! hash_equals((string) $fresh->hub_image_hash, hash('sha256', $bytes))) {
            throw new MirrorIntegrityException(
                'Your signature image could not be verified against the hub. Try again in a moment.'
            );
        }

        return $fresh;
    }

    /** No signature at the hub any more: retire whatever mirror is held. */
    protected function clear(int $userId, string $reason): void
    {
        Signature::query()
            ->where('user_id', $userId)
            ->whereNull('signable_id')
            ->where('source', 'hub')
            ->where('status', '!=', 'revoked')
            ->get()
            ->each(fn (Signature $mirror) => $this->retire($mirror, $reason));
    }

    protected function retire(Signature $mirror, string $reason): void
    {
        MirrorStorage::delete($mirror);

        $mirror->update(['status' => 'revoked', 'revoked_at' => now()]);

        SignatureAudit::record(SignatureAudit::SIGNATURE_REVOKED, [
            'subject_user_id' => $mirror->user_id,
            'signature_id'    => $mirror->id,
            'context'         => ['source' => 'hub', 'hub_uuid' => $mirror->hub_uuid, 'reason' => $reason],
        ]);
    }
}
