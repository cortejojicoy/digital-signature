<?php

namespace Kukux\DigitalSignature\Filament\Actions\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use Kukux\DigitalSignature\Documents\TemplateDocument;
use Kukux\DigitalSignature\Routing\RoutingResult;
use Kukux\DigitalSignature\Services\AutoAffixService;
use Kukux\DigitalSignature\Services\DocumentRouter;

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

    /**
     * Routes through DocumentRouter, the same path every document takes: a
     * record with no SignableDocument of its own is routed with its template
     * and the package's checks (TemplateDocument).
     */
    protected function handleRequest(mixed $record): void
    {
        if (! $record instanceof Model) {
            $this->notifyFailure('No record', 'This action must run against a record.');

            return;
        }

        try {
            $result = app(DocumentRouter::class)->route(
                new TemplateDocument($this->templateKey ?? $this->templateKeyOf($record)),
                $record,
            );

            $affixed = $this->autoAffix && $result->ok()
                ? app(AutoAffixService::class)->process($result->session())
                : [];
        } catch (\Throwable $e) {
            report($e);

            $failed = RoutingResult::refused('failed', ['error' => $e->getMessage()]);
            $this->notifyFailure($failed->title(), $failed->body());

            return;
        }

        if ($result->wasRefused()) {
            $this->notifyFailure($result->title(), $result->body());

            return;
        }

        $body = $result->body();

        if ($affixed !== []) {
            $body .= ' '.trans_choice(
                '{1} One signature was applied under a standing authorisation.|[2,*] :count signatures were applied under standing authorisations.',
                count($affixed),
                ['count' => count($affixed)],
            );
        }

        Notification::make()
            ->title($result->title())
            ->body($body)
            ->success()
            ->send();
    }

    protected function templateKeyOf(Model $record): string
    {
        if (method_exists($record, 'signatureTemplateKey')) {
            return $record->signatureTemplateKey();
        }

        throw new \InvalidArgumentException(sprintf(
            'No template given and [%s] does not expose signatureTemplateKey(). Use '
            .'->template(\'…\') or the HasPdfTemplate trait.',
            $record::class,
        ));
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
