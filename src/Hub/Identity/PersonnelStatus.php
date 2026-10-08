<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;

/**
 * A person's standing at the hub, as the admin Personnel page shows it
 * (plan 1.7): unclaimed / pending / verified / separated, their paired
 * computer and when their signature was last used. By personnel key, so a
 * person's earlier (retired) accounts don't count against them.
 */
final class PersonnelStatus
{
    public const UNCLAIMED = 'unclaimed';

    public const LABELS = [
        self::UNCLAIMED            => 'Unclaimed',
        Identity::PENDING          => 'Pending',
        Identity::VERIFIED         => 'Verified',
        Identity::SEPARATED        => 'Separated',
    ];

    /** Audit events that count as using the signature. */
    public const USES = [
        SignatureAudit::HUB_SIGNED,
        SignatureAudit::REQUEST_SIGNED,
        SignatureAudit::AGENT_APPROVED,
        SignatureAudit::SIGNATURE_AUTO_AFFIXED,
    ];

    public static function of(string $personnelKey): string
    {
        if ($current = Identity::current($personnelKey)) {
            return $current->status;
        }

        return Identity::query()->where('personnel_key', $personnelKey)->where('status', Identity::SEPARATED)->exists()
            ? Identity::SEPARATED
            : self::UNCLAIMED;
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst($status);
    }

    public static function computer(string $personnelKey): ?SigningDevice
    {
        $userId = Identity::current($personnelKey)?->user_id;

        return $userId === null ? null : app(IdentityService::class)->computer((int) $userId);
    }

    public static function lastUse(string $personnelKey): ?\Illuminate\Support\Carbon
    {
        $at = SignatureAudit::query()
            ->where('personnel_key', $personnelKey)
            ->whereIn('event', self::USES)
            ->max('created_at');

        return $at === null ? null : \Illuminate\Support\Carbon::parse($at);
    }

    /**
     * Personnel keys in a status, for the Personnel page's filter. Plucked,
     * not a subquery: the registry may live on another connection.
     *
     * @return array<int, string>
     */
    public static function keysIn(string $status): array
    {
        $query = Identity::query()->whereNotNull('personnel_key');

        return match ($status) {
            Identity::PENDING, Identity::VERIFIED => $query->where('status', $status)->pluck('personnel_key')->unique()->values()->all(),
            Identity::SEPARATED => $query->where('status', Identity::SEPARATED)
                ->whereNotIn('personnel_key', Identity::query()->whereIn('status', [Identity::PENDING, Identity::VERIFIED])->whereNotNull('personnel_key')->select('personnel_key'))
                ->pluck('personnel_key')->unique()->values()->all(),
            // Unclaimed is "none of the above": callers use whereNotIn on this.
            default => $query->whereIn('status', [Identity::PENDING, Identity::VERIFIED, Identity::SEPARATED])->pluck('personnel_key')->unique()->values()->all(),
        };
    }

    /** @return array<int, string> keys whose current account has an active paired computer */
    public static function keysWithComputer(): array
    {
        return Identity::query()
            ->whereIn('status', [Identity::PENDING, Identity::VERIFIED])
            ->whereNotNull('personnel_key')
            ->whereIn('user_id', SigningDevice::query()->where('kind', 'agent')->active()->select('user_id'))
            ->pluck('personnel_key')->unique()->values()->all();
    }

    /** @return array<int, string> keys that used their signature since $since */
    public static function keysUsedSince(\DateTimeInterface $since): array
    {
        return SignatureAudit::query()
            ->whereNotNull('personnel_key')
            ->whereIn('event', self::USES)
            ->where('created_at', '>=', $since)
            ->distinct()
            ->pluck('personnel_key')->all();
    }
}
