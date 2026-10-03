<?php

namespace Kukux\DigitalSignature\Filament\Concerns;

use Filament\Support\Exceptions\Halt;
use Kukux\DigitalSignature\Contracts\SignableDocument;
use Kukux\DigitalSignature\Filament\Support\RoutingNotification;
use Kukux\DigitalSignature\Routing\RoutingResult;
use Kukux\DigitalSignature\Services\DocumentRouter;

/**
 * For a page that routes from somewhere other than its own action, typically
 * a modal footer button whose `->action()` branches on a mode:
 *
 *   if ($mode === 'route') {
 *       $this->routeDocument('accomplishment-report', $this->currentPersonnel(), $this->dateRange);
 *
 *       return;
 *   }
 *
 * Routes through DocumentRouter, notifies, and on a refusal throws Halt so
 * the modal stays open for the user to fix what it names.
 */
trait RoutesDocumentsForSignatures
{
    /**
     * @param  array<string, mixed>  $context
     *
     * @throws Halt when routing refused
     */
    protected function routeDocument(string|SignableDocument $document, mixed $subject, array $context = []): RoutingResult
    {
        $result = app(DocumentRouter::class)->route($document, $subject, $context);

        RoutingNotification::send($result);

        if ($result->wasRefused()) {
            throw new Halt;
        }

        return $result;
    }
}
