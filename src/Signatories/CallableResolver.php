<?php

namespace Kukux\DigitalSignature\Signatories;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\SignatoryResolver;
use Kukux\DigitalSignature\Pdf\SlotDefinition;

/**
 * Resolves a signatory by calling host-supplied code.
 *
 * Covers both the closure form —
 *   'signatory' => fn (Model $record) => $record->department->head
 * and the invokable-class form —
 *   'signatory' => \App\Signatories\DepartmentHead::class
 * which is resolved through the container so it can take constructor
 * dependencies and be unit-tested on its own.
 *
 * The callable receives ($record, $slot) so one invokable can serve several
 * slots and branch on $slot->role().
 */
class CallableResolver implements SignatoryResolver
{
    /** @var callable */
    protected $callback;

    public function __construct(callable|Closure $callback)
    {
        $this->callback = $callback;
    }

    /**
     * Build from an invokable class name, resolving it through the container.
     *
     * @param  class-string  $class
     */
    public static function fromClass(string $class): self
    {
        return new self(function (Model $record, SlotDefinition $slot) use ($class) {
            $instance = app($class);

            // An invokable that implements the contract gets the richer call.
            if ($instance instanceof SignatoryResolver) {
                return $instance->resolve($record, $slot);
            }

            return $instance($record, $slot);
        });
    }

    public function resolve(Model $record, SlotDefinition $slot): Authenticatable|Model|null
    {
        $result = ($this->callback)($record, $slot);

        if ($result instanceof Authenticatable || $result instanceof Model) {
            return $result;
        }

        // A callback may return a bare id — accept it for convenience.
        if (is_int($result) || (is_string($result) && $result !== '')) {
            $userModel = config('auth.providers.users.model');

            if (is_string($userModel) && class_exists($userModel)) {
                return $userModel::find($result);
            }
        }

        return null;
    }
}
