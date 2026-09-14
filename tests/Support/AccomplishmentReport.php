<?php

namespace Kukux\DigitalSignature\Tests\Support;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Kukux\DigitalSignature\Concerns\HasPdfTemplate;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Traits\HasSignatories;

/**
 * Stand-in for the host app's Accomplishment Report: three roles, each a
 * belongsTo on the record — exactly the integration shape the docs describe.
 */
class AccomplishmentReport extends Model implements Signable
{
    use HasPdfTemplate;
    use HasSignatories;

    protected $table = 'accomplishment_reports';

    protected $guarded = [];

    protected string $signaturePdfTemplate = 'accomplishment-report';

    public function preparedBy(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'prepared_by_id');
    }

    public function attestedBy(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'attested_by_id');
    }

    public function notedBy(): BelongsTo
    {
        return $this->belongsTo(TestUser::class, 'noted_by_id');
    }

    public function getSignableTitle(): string
    {
        return 'AR #'.$this->getKey();
    }
}
