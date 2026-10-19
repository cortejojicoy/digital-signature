<?php

namespace Kukux\DigitalSignature\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Who may use one gated feature: everyone, selected people, or super admins
 * only. Written from the launcher's Settings tab; read through
 * Support\FeatureAccess, never directly.
 *
 * `user_ids` is kept when the mode changes, so switching a feature back to
 * "Selected people" after a test brings the same people back.
 *
 * @property string $feature
 * @property string $mode
 * @property array<int, int|string>|null $user_ids
 * @property int|null $updated_by
 */
class FeatureRule extends Model
{
    protected $table = 'digital_signature_feature_access';

    protected $fillable = ['feature', 'mode', 'user_ids', 'updated_by'];

    /** @var array<string, string> */
    protected $casts = [
        'user_ids' => 'array',
    ];

    /**
     * The selected users' ids, as strings so int and string keys compare alike.
     *
     * @return array<int, string>
     */
    public function userIds(): array
    {
        $ids = is_array($this->user_ids) ? $this->user_ids : [];

        return array_values(array_unique(array_map('strval', $ids)));
    }
}
