<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kukux\DigitalSignature\Signatories\RouteState;

/**
 * One slot's worth of work inside a signing session.
 *
 * This is the row a signatory acts on: it names the person, the slot, the
 * frozen placement they agreed to, and the decision they made. The placement
 * is copied here rather than read live from `digital_pdf_template_slots` so
 * that an admin moving a slot in the designer cannot retroactively change
 * where an already-requested signature will land.
 */
class SignatureRequest extends Model
{
    protected $table = 'digital_signature_requests';

    protected $fillable = [
        'uuid',
        'signing_session_id',
        'slot_key', 'role',
        'user_id', 'signature_id',
        'sequence', 'required', 'state',
        'page', 'x', 'y', 'width', 'height',
        'requested_at', 'responded_at', 'declined_reason',
    ];

    protected $casts = [
        'required'     => 'boolean',
        'state'        => RouteState::class,
        'sequence'     => 'integer',
        'page'         => 'integer',
        'x'            => 'float',
        'y'            => 'float',
        'width'        => 'float',
        'height'       => 'float',
        'requested_at' => 'datetime',
        'responded_at' => 'datetime',
    ];

    public function session(): BelongsTo
    {
        return $this->belongsTo(SigningSession::class, 'signing_session_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(config('auth.providers.users.model'));
    }

    public function signature(): BelongsTo
    {
        return $this->belongsTo(Signature::class, 'signature_id');
    }

    public function scopeForUser(Builder $query, int $userId): Builder
    {
        return $query->where('user_id', $userId);
    }

    /**
     * Requests the given user still has to act on, newest session first.
     * This is the query behind the signatory's inbox.
     */
    public function scopeOutstandingFor(Builder $query, int $userId): Builder
    {
        return $query
            ->where('user_id', $userId)
            ->whereIn('state', [
                RouteState::AwaitingConsent->value,
                RouteState::Ready->value,
            ])
            ->whereHas('session', fn (Builder $q) => $q->where('status', SigningSession::STATUS_OPEN));
    }

    public function isSigned(): bool
    {
        return $this->state === RouteState::Signed;
    }

    public function isDeclined(): bool
    {
        return $this->state === RouteState::Declined;
    }

    /**
     * The frozen placement as a position array, or null when this request was
     * created before the slot had any coordinates.
     *
     * @return array{page:int,x:float,y:float,width:float,height:float}|null
     */
    public function position(): ?array
    {
        if ($this->page === null || $this->x === null || $this->y === null) {
            return null;
        }

        return [
            'page'   => $this->page,
            'x'      => $this->x,
            'y'      => $this->y,
            'width'  => $this->width,
            'height' => $this->height,
        ];
    }
}
