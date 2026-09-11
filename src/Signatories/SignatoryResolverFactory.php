<?php

namespace Kukux\DigitalSignature\Signatories;

use Closure;
use Kukux\DigitalSignature\Contracts\SignatoryResolver;
use Kukux\DigitalSignature\Pdf\SlotDefinition;

/**
 * Turns a slot's `signatory` binding — whatever shape the host wrote it in —
 * into a SignatoryResolver.
 *
 * Keeping the shape-sniffing here means the router, the designer and any
 * host code that wants to resolve a signatory all agree on what
 * 'attestedBy' versus \App\Signatories\Dean::class means.
 */
class SignatoryResolverFactory
{
    /** @var Closure|null Global override registered via SignaturePlugin::resolveSignatoriesUsing() */
    protected ?Closure $override = null;

    /** @var array<string, SignatoryResolver> */
    protected array $cache = [];

    /**
     * Install a global resolver that takes precedence over every slot's own
     * binding. Receives ($record, $slot) and may return null to fall through
     * to the slot's declared binding.
     */
    public function overrideUsing(?Closure $callback): static
    {
        $this->override = $callback;

        return $this;
    }

    public function hasOverride(): bool
    {
        return $this->override !== null;
    }

    public function override(): ?Closure
    {
        return $this->override;
    }

    /**
     * Build the resolver for a slot, or null when the slot is unrouted.
     */
    public function make(SlotDefinition $slot): ?SignatoryResolver
    {
        $binding = $slot->signatory;

        if ($binding === null) {
            return null;
        }

        if ($binding instanceof Closure) {
            // Closures can't be cached by value — build fresh each time.
            return new CallableResolver($binding);
        }

        return $this->cache[$binding] ??= $this->fromString($binding);
    }

    /**
     * A string binding is either a class name (invokable / SignatoryResolver)
     * or a relation-or-column name on the record.
     */
    protected function fromString(string $binding): SignatoryResolver
    {
        if (class_exists($binding)) {
            if (is_subclass_of($binding, SignatoryResolver::class)) {
                return app($binding);
            }

            if (method_exists($binding, '__invoke')) {
                return CallableResolver::fromClass($binding);
            }

            throw new \InvalidArgumentException(sprintf(
                'Signatory binding [%s] is a class but neither implements %s nor is invokable.',
                $binding,
                SignatoryResolver::class,
            ));
        }

        return new RelationResolver($binding);
    }
}
