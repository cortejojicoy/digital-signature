<?php

namespace Kukux\DigitalSignature\Filament\Actions\Concerns;

use Closure;
use Kukux\DigitalSignature\DocumentOfRecord\DocumentOfRecordResolver;

/**
 * Downloads the document: its document of record once routed, otherwise the
 * live draft from `->live()`. Without `->live()` it's hidden until routed.
 *
 *   DownloadDocumentOfRecordAction::make()->document('dtr')
 *       ->subject(fn () => $this->employee)->context(fn () => ['month' => $this->month])
 *       ->live(fn () => DtrPdf::render($this->employee, $this->month))
 *       ->filename('DTR.pdf');
 */
trait DownloadsDocumentOfRecord
{
    use TargetsSignableDocument;

    protected ?Closure $liveRender = null;

    protected string|Closure $downloadFilename = 'document.pdf';

    public static function getDefaultName(): ?string
    {
        return 'download_document_of_record';
    }

    /** @param  Closure(): string  $render  the draft's PDF bytes */
    public function live(?Closure $render): static
    {
        $this->liveRender = $render;

        return $this;
    }

    public function filename(string|Closure $filename): static
    {
        $this->downloadFilename = $filename;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label('Download');
        $this->icon('heroicon-o-arrow-down-tray');
        $this->color('gray');
        $this->visible(fn (): bool => $this->liveRender !== null || $this->getDocumentOfRecord() !== null);

        $this->action(function () {
            $live = $this->liveRender ?? fn (): string => throw new \RuntimeException(
                'This document has not been routed, and the action has no ->live() renderer for a draft.',
            );

            return app(DocumentOfRecordResolver::class)
                ->resolve(
                    $this->getDocumentDefinition(),
                    $this->getDocumentSubject(),
                    $this->getDocumentContext(),
                    fn (): string => (string) $this->evaluate($live),
                )
                ->download((string) $this->evaluate($this->downloadFilename));
        });
    }
}
