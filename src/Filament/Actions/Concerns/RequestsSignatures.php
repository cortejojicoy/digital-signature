<?php

namespace Kukux\DigitalSignature\Filament\Actions\Concerns;

use Filament\Notifications\Notification;
use Kukux\DigitalSignature\Services\AutoAffixService;
use Kukux\DigitalSignature\Services\SignatoryRouter;
use Kukux\DigitalSignature\Services\SigningSessionManager;

/**
 * Opens (or advances) a signing session for the record the action sits on.
 *
 * Deliberately refuses to open a session whose required roles cannot be
 * filled: a session that stalls on "the Dean has no signature" is worse than
 * no session, because the document then looks like it is in progress when
 * nothing can actually happen. The action names the blockers instead.
 */
trait RequestsSignatures
{
    protected ?string $templateKey = null;

    protected bool $autoAffix = true;

    public static function getDefaultName(): ?string
    {
        return 'request_signatures';
    }

    /**
     * Override the template key. Defaults to the record's own
     * signatureTemplateKey().
     */
    public function template(string $key): static
    {
        $this->templateKey = $key;

        return $this;
    }

    /**
     * Whether to attempt delegated auto-affix right after opening. Has no
     * effect unless the template's consent mode permits it.
     */
    public function autoAffix(bool $condition = true): static
    {
        $this->autoAffix = $condition;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Request signatures');
        $this->icon('heroicon-o-paper-airplane');
        $this->requiresConfirmation();
        $this->modalHeading('Request signatures');
        $this->modalDescription(
            'This freezes the current version of the document and asks each assigned '
            .'signatory to sign it. Later edits to the record will not change what they sign.'
        );

        $this->action(fn ($record = null) => $this->handleRequest($record));
    }

    protected function handleRequest(mixed $record): void
    {
        if ($record === null) {
            $this->notifyFailure('No record', 'This action must run against a record.');

            return;
        }

        $router = app(SignatoryRouter::class);

        try {
            $blockers = $router->blockers($record, $this->templateKey);
        } catch (\Throwable $e) {
            $this->notifyFailure('Cannot route signatories', $e->getMessage());

            return;
        }

        // Only unassigned / unregistered roles stop us — an outstanding
        // signature is exactly what we're about to ask for.
        $fatal = array_filter(
            $router->routeFor($record, $this->templateKey),
            fn ($route) => $route->isRequired() && in_array($route->state->value, [
                'unassigned',
                'awaiting_registration',
            ], true),
        );

        if ($fatal !== []) {
            $this->notifyFailure(
                'Not ready for signatures',
                implode(' ', array_map(
                    fn ($route) => $route->blockerMessage() ?? $route->state->label(),
                    $fatal,
                )),
            );

            return;
        }

        try {
            $session = app(SigningSessionManager::class)->open($record, $this->templateKey);

            $affixed = $this->autoAffix
                ? app(AutoAffixService::class)->process($session)
                : [];
        } catch (\Throwable $e) {
            report($e);
            $this->notifyFailure('Could not open signing session', $e->getMessage());

            return;
        }

        $pending = count($blockers);

        Notification::make()
            ->title('Signatures requested')
            ->body($affixed === []
                ? sprintf('%d signator%s notified.', $pending, $pending === 1 ? 'y' : 'ies')
                : sprintf(
                    '%d signature%s applied automatically under standing authorisations; the rest were notified.',
                    count($affixed),
                    count($affixed) === 1 ? '' : 's',
                ))
            ->success()
            ->send();
    }

    protected function notifyFailure(string $title, string $body): void
    {
        Notification::make()
            ->title($title)
            ->body($body)
            ->danger()
            ->send();

        $this->halt();
    }
}
