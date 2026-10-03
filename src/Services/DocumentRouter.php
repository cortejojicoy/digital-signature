<?php

namespace Kukux\DigitalSignature\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Kukux\DigitalSignature\Contracts\PdfTemplate;
use Kukux\DigitalSignature\Contracts\PreflightGuard;
use Kukux\DigitalSignature\Contracts\RoutingGuard;
use Kukux\DigitalSignature\Contracts\Signable;
use Kukux\DigitalSignature\Contracts\SignableDocument;
use Kukux\DigitalSignature\Models\SigningSession;
use Kukux\DigitalSignature\Routing\Guards\RequiredSignatoriesAssigned;
use Kukux\DigitalSignature\Routing\Guards\SignatoriesReady;
use Kukux\DigitalSignature\Routing\Guards\SignatureMarkersPresent;
use Kukux\DigitalSignature\Routing\RoutingResult;
use Kukux\DigitalSignature\Signatories\SignatoryRoute;
use Kukux\DigitalSignature\Support\HumanList;

/**
 * Routes a document for signatures: the one implementation every app shares.
 *
 *   app(DocumentRouter::class)->route('accomplishment-report', $personnel, $period);
 *
 * In order:
 *
 *  1. The definition's preflight guards, before anything is written.
 *  2. A transaction opens, and the definition opens (finds or creates) the record.
 *  3. A session already open for it → "already routed": any signatory tagged
 *     since it opened is filled in, and the transaction commits.
 *  4. The definition's guards, then the package's: required signatories set,
 *     every required slot has somewhere to go, everyone named can sign.
 *     The first refusal rolls back, so **a refusal leaves no row behind**.
 *  5. The session opens (the PDF is frozen, requests are created, the first
 *     signatory is notified) and the transaction commits.
 *  6. Any exception rolls back and is rethrown.
 *
 * The transaction is driven by hand rather than with DB::transaction():
 * a refusal is an ordinary return value, which the closure form would commit.
 */
class DocumentRouter
{
    public function __construct(
        protected DocumentRegistry $documents,
        protected PdfTemplateRegistry $templates,
        protected SignatoryRouter $router,
        protected SigningSessionManager $sessions,
    ) {
    }

    /**
     * @param  string|SignableDocument  $document  a registered key, a class-string or an instance
     * @param  mixed  $subject  whatever the definition's open() takes: the record, or what it's built from
     * @param  array<string, mixed>  $context  e.g. a period; passed to open() and every guard
     */
    public function route(string|SignableDocument $document, mixed $subject, array $context = []): RoutingResult
    {
        $definition = $this->documents->resolve($document);
        $template = $this->templates->get($definition->template());
        $messages = $definition->messages();

        foreach ($definition->preflight() as $guard) {
            $result = $this->preflightGuard($guard)->check($subject, $context);

            if ($result !== null && $result->wasRefused()) {
                return $result->withMessages($messages);
            }
        }

        DB::beginTransaction();

        try {
            $record = $definition->open($subject, $context);

            $this->assertSignable($record, $definition);

            // open() may have just changed who the record names.
            $this->router->forgetResolved($record);

            if (($session = $this->openSessionFor($record, $template)) !== null) {
                // Someone tagged since it opened (a role that was empty, say)
                // gets their request now, as reopening always did.
                $this->sessions->refreshAssignments($session);

                DB::commit();

                return RoutingResult::alreadyRouted($record, $session)->withMessages($messages);
            }

            foreach ($this->guardsFor($definition) as $guard) {
                $result = $this->routingGuard($guard)->check($record, $template, $context);

                if ($result !== null && $result->wasRefused()) {
                    DB::rollBack();

                    return $result->withMessages($messages);
                }
            }

            $session = $this->sessions->open($record, $template->key());

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            throw $e;
        }

        return RoutingResult::routed($record, $session, [
            'names' => $this->signatoryNames($record, $template),
        ])->withMessages($messages);
    }

    /**
     * The definition's checks, then the package's, in the order they should
     * explain themselves: who is missing before where they go before whether
     * they can sign.
     *
     * @return list<class-string<RoutingGuard>|RoutingGuard>
     */
    protected function guardsFor(SignableDocument $definition): array
    {
        return [
            ...$definition->guards(),
            RequiredSignatoriesAssigned::class,
            SignatureMarkersPresent::class,
            SignatoriesReady::class,
        ];
    }

    protected function routingGuard(string|RoutingGuard $guard): RoutingGuard
    {
        $instance = is_string($guard) ? app($guard) : $guard;

        if (! $instance instanceof RoutingGuard) {
            throw new \InvalidArgumentException(sprintf(
                '%s is listed as a routing guard but does not implement %s.',
                get_debug_type($instance),
                RoutingGuard::class,
            ));
        }

        return $instance;
    }

    protected function preflightGuard(string|PreflightGuard $guard): PreflightGuard
    {
        $instance = is_string($guard) ? app($guard) : $guard;

        if (! $instance instanceof PreflightGuard) {
            throw new \InvalidArgumentException(sprintf(
                '%s is listed as a preflight guard but does not implement %s.',
                get_debug_type($instance),
                PreflightGuard::class,
            ));
        }

        return $instance;
    }

    protected function assertSignable(mixed $record, SignableDocument $definition): void
    {
        if ($record instanceof Model && $record instanceof Signable) {
            return;
        }

        throw new \LogicException(sprintf(
            '%s::open() returned %s. It must return the Eloquent model to sign, implementing %s '
            .'(use the HasPdfTemplate and HasSignatories traits).',
            $definition::class,
            get_debug_type($record),
            Signable::class,
        ));
    }

    protected function openSessionFor(Model $record, PdfTemplate $template): ?SigningSession
    {
        return SigningSession::query()
            ->forSignable($record)
            ->where('template_key', $template->key())
            ->open()
            ->latest('id')
            ->first();
    }

    /** Who it was sent to, in signing order, for the "Sent to …" line. */
    protected function signatoryNames(Model $record, PdfTemplate $template): string
    {
        $names = [];

        foreach ($this->router->routeFor($record, $template->key()) as $route) {
            // The person as the document names them (a Personnel row's full
            // name) rather than their login's display name, when they differ.
            $name = SignatoryRoute::nameOf($route->tagged) ?? $route->signerName();

            if ($name !== null && ! in_array($name, $names, true)) {
                $names[] = $name;
            }
        }

        return HumanList::join($names) ?: 'its signatories';
    }
}
