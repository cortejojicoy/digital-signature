<?php

namespace Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages\V3;

use Filament\Resources\Pages\ViewRecord;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages\Concerns\IsViewSignature;

/**
 * Filament v3: Page::$view is a STATIC property.
 */
class ViewSignature extends ViewRecord
{
    use IsViewSignature;

    protected static string $resource = SignatureResource::class;

    /**
     * Custom view renders the default infolist plus a card grid below it
     * listing every registered PdfTemplate this signature can be applied to.
     */
    protected static string $view = 'signature::filament.pages.view-signature-with-templates';
}
