<?php

namespace Kukux\DigitalSignature\Filament\Actions\Concerns;

use Closure;
use Filament\Support\Exceptions\Halt;
use Kukux\DigitalSignature\Filament\Support\RoutingNotification;
use Kukux\DigitalSignature\Routing\RoutingResult;
use Kukux\DigitalSignature\Services\DocumentRouter;

/**
 * "Route for signatures": DocumentRouter behind a button.
 *
 *   RouteForSignaturesAction::make()->document('travel-request')            // record page
 *   RouteForSignaturesAction::make()->document('dtr')
 *       ->subject(fn () => $this->employee)->context(fn () => ['month' => $this->month])
 *
 * A refusal is a warning naming what to fix, and halts, so a surrounding
 * modal stays open for the user to fix it and try again.
 */
trait RoutesForSignatures
{
    use TargetsSignableDocument;

    protected ?Closure $afterRouting = null;

    public static function getDefaultName(): ?string
    {
        return 'route_for_signatures';
    }

    /** Called with the RoutingResult after a successful route. */
    public function afterRouting(?Closure $callback): static
    {
        $this->afterRouting = $callback;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Route for signatures');
        $this->icon('heroicon-o-paper-airplane');
        $this->requiresConfirmation();
        $this->modalHeading('Route for signatures');
        $this->modalDescription(
            'This freezes the document as it is now and sends it to its signatories. '
            .'Later changes to the data behind it will not change what they sign.'
        );

        $this->action(fn () => $this->routeForSignatures());
    }

    protected function routeForSignatures(): RoutingResult
    {
        try {
            $result = app(DocumentRouter::class)->route(
                $this->getDocumentDefinition(),
                $this->getDocumentSubject(),
                $this->getDocumentContext(),
            );
        } catch (Halt $halt) {
            throw $halt;
        } catch (\Throwable $e) {
            report($e);

            $result = RoutingResult::refused('failed', ['error' => $e->getMessage()]);
        }

        RoutingNotification::send($result);

        if ($result->wasRefused()) {
            $this->halt();
        }

        if ($this->afterRouting !== null) {
            $this->evaluate($this->afterRouting, ['result' => $result]);
        }

        return $result;
    }
}
