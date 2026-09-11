<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

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

    protected $table = 'digital_signature_audits';

    protected $fillable = [
        'uuid', 'event',
        'subject_user_id', 'actor_user_id', 'actor_type',
        'signing_session_id', 'signature_request_id', 'signature_id', 'delegation_id',
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
        ], $attributes));
    }
}
