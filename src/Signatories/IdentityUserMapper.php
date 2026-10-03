<?php

namespace Kukux\DigitalSignature\Signatories;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\SignatoryUserMapper;

/**
 * The default mapper: a login passes through, anything else is refused.
 *
 * Right for apps whose records tag users directly. An app whose records tag
 * some other model binds its own mapper; until it does, those slots route as
 * unassigned rather than to the wrong person.
 */
class IdentityUserMapper implements SignatoryUserMapper
{
    public function toUser(Model $resolved): ?Authenticatable
    {
        return $resolved instanceof Authenticatable ? $resolved : null;
    }
}
