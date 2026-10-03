<?php

namespace Kukux\DigitalSignature\Services;

use Kukux\DigitalSignature\Contracts\SignableDocument;

/**
 * The documents an app routes for signatures, by key.
 *
 * Filled from config('signature.documents') at boot, and from
 * SignaturePlugin::make()->documents([...]) or register() at runtime.
 * Definitions are stored as class-strings and built through the container on
 * first use, so they can depend on app services and cost nothing until used.
 */
class DocumentRegistry
{
    /** @var array<string, class-string<SignableDocument>|SignableDocument> */
    protected array $documents = [];

    /** @var array<string, SignableDocument> */
    protected array $resolved = [];

    /** @param  class-string<SignableDocument>|SignableDocument  $document */
    public function register(string $key, string|SignableDocument $document): static
    {
        $this->documents[$key] = $document;
        unset($this->resolved[$key]);

        return $this;
    }

    /** @param  iterable<string, class-string<SignableDocument>|SignableDocument>  $documents */
    public function registerMany(iterable $documents): static
    {
        foreach ($documents as $key => $document) {
            if (! is_string($key)) {
                throw new \InvalidArgumentException(
                    'Documents are registered by key: [\'dtr\' => DtrDocument::class]. Got a list entry instead.',
                );
            }

            $this->register($key, $document);
        }

        return $this;
    }

    public function has(string $key): bool
    {
        return isset($this->documents[$key]);
    }

    public function get(string $key): SignableDocument
    {
        if (isset($this->resolved[$key])) {
            return $this->resolved[$key];
        }

        $document = $this->documents[$key]
            ?? throw new \InvalidArgumentException(sprintf(
                'No signable document registered as [%s]. Add it to config(\'signature.documents\').',
                $key,
            ));

        $instance = is_string($document) ? app($document) : $document;

        if (! $instance instanceof SignableDocument) {
            throw new \InvalidArgumentException(sprintf(
                'Document [%s] must implement %s, got %s.',
                $key,
                SignableDocument::class,
                get_debug_type($instance),
            ));
        }

        return $this->resolved[$key] = $instance;
    }

    /**
     * A definition given by key, by class-string or as an instance.
     *
     * @param  string|SignableDocument  $document
     */
    public function resolve(string|SignableDocument $document): SignableDocument
    {
        if ($document instanceof SignableDocument) {
            return $document;
        }

        if ($this->has($document)) {
            return $this->get($document);
        }

        if (class_exists($document) && is_subclass_of($document, SignableDocument::class)) {
            return app($document);
        }

        return $this->get($document);
    }

    /** The key a definition is registered under, if any. */
    public function keyOf(SignableDocument $document): ?string
    {
        foreach ($this->documents as $key => $registered) {
            if ($registered === $document || $registered === $document::class) {
                return $key;
            }
        }

        return null;
    }

    /** @return array<string, class-string<SignableDocument>|SignableDocument> */
    public function all(): array
    {
        return $this->documents;
    }
}
