<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Security\DeviceRegistry;

/**
 * Append-only audit row. Never updated, never deleted by package code.
 *
 * `record()` is the only way this package writes audits, so every call site
 * captures the request context the same way.
 */
class SignatureAudit extends Model
{
    public const SESSION_OPENED = 'session.opened';

    public const SESSION_COMPLETED = 'session.completed';

    public const SESSION_CANCELLED = 'session.cancelled';

    public const REQUEST_CREATED = 'request.created';

    public const REQUEST_SIGNED = 'request.signed';

    public const REQUEST_DECLINED = 'request.declined';

    public const SIGNATURE_AUTO_AFFIXED = 'signature.auto_affixed';

    public const DELEGATION_GRANTED = 'delegation.granted';

    public const DELEGATION_REVOKED = 'delegation.revoked';

    public const AGENT_PAIRED = 'agent.paired';

    public const AGENT_APPROVED = 'agent.approved';

    /** The same user re-paired a computer: the existing device got new keys. */
    public const AGENT_REBOUND = 'agent.rebound';

    /** An admin freed a computer from the account it was paired with. */
    public const AGENT_RELEASED = 'agent.released';

    protected $table = 'digital_signature_audits';

    protected $fillable = [
        'uuid', 'event',
        'subject_user_id', 'actor_user_id', 'actor_type',
        'signing_session_id', 'signature_request_id', 'signature_id', 'delegation_id', 'device_id',
        'ip', 'user_agent', 'context',
    ];

    protected $casts = [
        'context' => 'array',
    ];

    public function subject(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'subject_user_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'), 'actor_user_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(SigningSession::class, 'signing_session_id');
    }

    /**
     * Write one audit row, capturing request provenance automatically.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function record(string $event, array $attributes = []): self
    {
        $actorId = $attributes['actor_user_id'] ?? auth()->id();

        // Distinguish a real HTTP actor from a queue worker or console run,
        // so an auto-affix is never mistaken for a person clicking "Sign".
        $actorType = $attributes['actor_type'] ?? match (true) {
            $actorId !== null                   => 'user',
            app()->runningInConsole()           => 'console',
            default                             => 'system',
        };

        $request = app()->bound('request') ? request() : null;

        return static::create(array_merge([
            'uuid'       => (string) Str::uuid(),
            'event'      => $event,
            'actor_user_id' => $actorId,
            'actor_type' => $actorType,
            'ip'         => $request?->ip(),
            'user_agent' => $request?->userAgent(),
            // The verified device only for the person actually present — a
            // system or delegated act has none, whatever the session holds.
            'device_id'  => $actorType === 'user' && $actorId !== null
                ? app(DeviceRegistry::class)->current((int) $actorId)?->id
                : null,
        ], $attributes));
    }
}
