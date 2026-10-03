<?php

namespace Kukux\DigitalSignature\Documents;

use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Contracts\SignableDocument;

/**
 * The base every document definition extends.
 *
 * Defaults fit a document whose record already exists, like a travel request
 * row, where the record is what the caller passes: `locate()` and `open()`
 * hand it straight back, and there are no extra checks. Such a document only
 * declares `template()`.
 *
 * A document generated from something else (a person and a period) overrides
 * `locate()` / `open()` to find or create its row.
 */
abstract class AbstractSignableDocument implements SignableDocument
{
    abstract public function template(): string;

    public function locate(mixed $subject, array $context = []): ?Model
    {
        return $subject instanceof Model && $subject->exists ? $subject : null;
    }

    public function open(mixed $subject, array $context = []): Model
    {
        if (! $subject instanceof Model) {
            throw new \InvalidArgumentException(sprintf(
                '%s was given %s to route. Pass the record, or override open() to find or create '
                .'it from what you have.',
                static::class,
                get_debug_type($subject),
            ));
        }

        return $subject;
    }

    public function preflight(): array
    {
        return [];
    }

    public function guards(): array
    {
        return [];
    }

    public function messages(): array
    {
        return [];
    }
}
