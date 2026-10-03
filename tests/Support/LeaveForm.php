<?php

namespace Kukux\DigitalSignature\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kukux\DigitalSignature\Concerns\HasPdfTemplate;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Traits\HasSignatories;
use Kukux\DigitalSignature\Traits\SnapshotsSignatories;

/**
 * A document generated from a person and a period, signed by that person and
 * their reviewer: the same shape as an accomplishment report or a DTR,
 * without either's domain.
 */
class LeaveForm extends Model implements Signable
{
    use HasPdfTemplate, HasSignatories, SnapshotsSignatories;

    protected $table = 'leave_forms';

    protected $guarded = [];

    protected string $signaturePdfTemplate = 'leave-form';

    public function signatoryRoles(): array
    {
        return ['reviewer'];
    }

    public function signatoryModel(): string
    {
        return Person::class;
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->signatoryRelation('reviewer');
    }

    public function getSignableTitle(): string
    {
        return 'Leave form #'.$this->getKey();
    }
}
