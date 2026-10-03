<?php

namespace Kukux\DigitalSignature\Signatories;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\SignatoryUserMapper;

/**
 * Follows one relation from the tagged person to their login.
 *
 * For the common shape where people live in their own table and a login
 * points at them, or they point at it (`Personnel::user()`, `Employee::user()`):
 *
 *   $this->app->bind(SignatoryUserMapper::class, fn () => RelationUserMapper::using('user'));
 *
 * A model that is already a login passes through unchanged, so a template can
 * mix slots bound to people and slots bound straight to users.
 */
class RelationUserMapper implements SignatoryUserMapper
{
    public function __construct(protected string $relation = 'user')
    {
    }

    public static function using(string $relation): static
    {
        return new static($relation);
    }

    public function toUser(Model $resolved): ?Authenticatable
    {
        if ($resolved instanceof Authenticatable) {
            return $resolved;
        }

        try {
            $user = $resolved->{$this->relation};
        } catch (\Throwable) {
            return null;
        }

        return $user instanceof Authenticatable ? $user : null;
    }
}
