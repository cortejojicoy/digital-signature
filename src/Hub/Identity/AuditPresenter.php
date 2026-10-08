<?php

namespace Kukux\DigitalSignature\Hub\Identity;

use Illuminate\Support\Str;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;

/**
 * One audit row the way the hub shows it (plan 1.7), on the admin Audit log,
 * a person's Audit trail and their own Profile:
 *
 *   when     the timestamp
 *   what     paired, claimed, verified, signed in, sign request, signed…
 *   where    the requesting app, the document (title, hash, slot, capacity), IP and browser
 *   how      the device (name, type, protection), presence method, proof purpose, specimen hash
 *   outcome  approved, refused (reason), expired…
 */
final class AuditPresenter
{
    private const WHAT = [
        SignatureAudit::AGENT_PAIRED       => 'Paired a computer',
        SignatureAudit::AGENT_REBOUND      => 'Re-paired a computer',
        SignatureAudit::AGENT_RELEASED     => 'Computer released',
        SignatureAudit::AGENT_APPROVED     => 'Approved on computer',
        SignatureAudit::IDENTITY_CLAIMED   => 'Claimed identity',
        SignatureAudit::IDENTITY_VERIFIED  => 'Identity verified',
        SignatureAudit::IDENTITY_REJECTED  => 'Identity rejected',
        SignatureAudit::IDENTITY_SEPARATED => 'Separated',
        SignatureAudit::TRANSFER_REQUESTED => 'Transfer requested',
        SignatureAudit::TRANSFER_APPROVED  => 'Transfer approved',
        SignatureAudit::TRANSFER_REJECTED  => 'Transfer refused',
        SignatureAudit::LOGIN_APPROVED     => 'Signed in',
        SignatureAudit::LOGIN_REJECTED     => 'Sign-in declined',
        SignatureAudit::BREAK_GLASS_USED   => 'Break-glass sign-in',
        SignatureAudit::HUB_SIGN_REQUESTED => 'Sign request',
        SignatureAudit::HUB_SIGN_REFUSED   => 'Sign request refused',
        SignatureAudit::HUB_SIGNED         => 'Signed',
        SignatureAudit::HUB_SIGN_DECLINED  => 'Declined to sign',
        SignatureAudit::HUB_IMAGE_SERVED   => 'Signature image sent to app',
        SignatureAudit::SIGNATURE_CREATED  => 'Signature drawn',
        SignatureAudit::SIGNATURE_REVOKED  => 'Signature revoked',
        SignatureAudit::ADMIN_GRANTED      => 'Made super admin',
        SignatureAudit::REQUEST_SIGNED     => 'Signed',
        SignatureAudit::REQUEST_DECLINED   => 'Declined to sign',
        SignatureAudit::SESSION_OPENED     => 'Signing session opened',
        SignatureAudit::SESSION_COMPLETED  => 'Signing session completed',
    ];

    /** @return array<string, string> */
    public static function events(): array
    {
        return self::WHAT;
    }

    /**
     * @return array{id: int, event: string, when: ?\Illuminate\Support\Carbon, what: string, where: string, how: string, outcome: string, actor_type: ?string}
     */
    public static function row(SignatureAudit $audit): array
    {
        return [
            'id'         => (int) $audit->id,
            'event'      => (string) $audit->event,
            'when'       => $audit->created_at,
            'what'       => self::what($audit->event),
            'where'      => self::where($audit),
            'how'        => self::how($audit),
            'outcome'    => self::outcome($audit),
            'actor_type' => $audit->actor_type,
        ];
    }

    public static function what(?string $event): string
    {
        return self::WHAT[$event] ?? Str::headline(str_replace('.', ' ', (string) $event));
    }

    public static function where(SignatureAudit $audit): string
    {
        $context = (array) ($audit->context ?? []);

        return collect([
            $audit->app,
            $context['title'] ?? $context['document_title'] ?? null,
            isset($context['document_hash']) ? 'doc '.Str::limit((string) $context['document_hash'], 12, '…') : null,
            isset($context['slot']) ? 'slot '.$context['slot'] : null,
            $context['capacity'] ?? null,
            $audit->ip,
            $audit->user_agent ? BrowserLabel::from($audit->user_agent) : ($context['browser'] ?? null),
        ])->filter(fn ($v) => filled($v))->map(fn ($v) => (string) $v)->join(' · ');
    }

    public static function how(SignatureAudit $audit): string
    {
        $context = (array) ($audit->context ?? []);
        $device = self::device($audit->device_id);

        return collect([
            $device ? $device->displayName().' ('.$device->deviceType()->label().', '.$device->protectionLabel().')' : null,
            $context['presence'] ?? ($device?->user_presence ? ($device->platform === 'Windows' ? 'Windows Hello' : 'Touch ID') : null),
            isset($context['purpose']) ? 'proof: '.$context['purpose'] : null,
            isset($context['specimen_hash']) ? 'specimen '.Str::limit((string) $context['specimen_hash'], 12, '…') : null,
            $audit->actor_type && $audit->actor_type !== 'user' ? $audit->actor_type : null,
        ])->filter(fn ($v) => filled($v))->join(' · ');
    }

    public static function outcome(SignatureAudit $audit): string
    {
        $context = (array) ($audit->context ?? []);
        $reason = $context['reason'] ?? $context['refusal_reason'] ?? null;

        return match ($audit->event) {
            SignatureAudit::LOGIN_REJECTED, SignatureAudit::TRANSFER_REJECTED, SignatureAudit::IDENTITY_REJECTED,
            SignatureAudit::HUB_SIGN_REFUSED, SignatureAudit::HUB_SIGN_DECLINED, SignatureAudit::REQUEST_DECLINED
                => 'Refused'.($reason ? " ({$reason})" : ''),
            SignatureAudit::SIGNATURE_REVOKED, SignatureAudit::AGENT_RELEASED, SignatureAudit::IDENTITY_SEPARATED
                => 'Revoked'.($reason ? " ({$reason})" : ''),
            SignatureAudit::TRANSFER_REQUESTED, SignatureAudit::HUB_SIGN_REQUESTED, SignatureAudit::IDENTITY_CLAIMED
                => 'Pending',
            default => ($context['status'] ?? null) === 'expired' ? 'Expired' : 'Approved',
        };
    }

    private static function device(?int $id): ?SigningDevice
    {
        return $id === null ? null : SigningDevice::query()->find($id);
    }
}
