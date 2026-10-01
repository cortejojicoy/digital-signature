<?php

namespace Kukux\DigitalSignature\Filament\Resources\SignatureResource\Pages;

use Filament\Resources\Pages\Page;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\RedirectResponse;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;
use Kukux\DigitalSignature\Models\Signature;

/**
 * Keeps old `/signatures/{record}` links working now that there is no View
 * page. A signature is managed in the launcher drawer, so this page never
 * renders: it sends the owner to the list with the drawer opened on that
 * signature, and anyone else to a 404.
 *
 * Not version-split. It declares no `$view` — the one property whose kind
 * changed between Filament majors — so it must not reach render(). Livewire
 * only applies `$this->redirect()` after rendering, which is why this throws
 * a plain redirect response instead.
 */
class OpenSignatureInDrawer extends Page
{
    protected static string $resource = SignatureResource::class;

    public function mount(int|string $record): void
    {
        $signature = Signature::query()
            ->where('user_id', auth()->id())
            ->where(fn ($query) => $query->whereKey($record)->orWhere('uuid', (string) $record))
            ->first();

        abort_unless($signature, 404);

        throw new HttpResponseException(new RedirectResponse(
            SignatureResource::getUrl('index').'?dsig=manage:'.$signature->uuid,
        ));
    }
}
