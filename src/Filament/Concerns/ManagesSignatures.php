<?php

namespace Kukux\DigitalSignature\Filament\Concerns;

use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Kukux\DigitalSignature\Models\PdfTemplateSlot;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;
use Kukux\DigitalSignature\Services\SignatureManager;

/**
 * "Manage signatures" inside the launcher drawer: what the View Signature page
 * used to do — details, download, revoke, and the templates a signature can be
 * applied to — without leaving the page the user is on.
 *
 * Ownership is the whole security story here, exactly as it is for
 * SignatureResource::getEloquentQuery(): a signature image is the artefact the
 * package exists to keep from being lifted. Every read and every action goes
 * through ownedSignature(), which matches on the uuid *and* the signed-in user,
 * so a uuid sent from the browser is a request, never a grant.
 */
trait ManagesSignatures
{
    /** Set on first entry into manage mode; the list is not built before. */
    public bool $manageLoaded = false;

    /** Uuid of the signature open in the detail pane, or null for the list. */
    public ?string $managing = null;

    /**
     * Enter manage mode, optionally with one signature selected.
     *
     * A uuid that is not this user's selects nothing rather than erroring: the
     * deep link and the library thumbnails are the only callers, and neither
     * should be able to tell a stranger's uuid from a mistyped one.
     */
    public function manage(?string $uuid = null): void
    {
        $this->manageLoaded = true;
        $this->loaded = true;
        $this->managing = $uuid !== null && $this->ownedSignature($uuid) ? $uuid : null;
    }

    public function revokeSignature(string $uuid): void
    {
        $signature = $this->ownedSignature($uuid);

        if (! $signature) {
            Notification::make()
                ->title('Signature not found')
                ->body('It may have been removed, or it is not yours to revoke.')
                ->danger()
                ->send();

            return;
        }

        if ($signature->isRevoked()) {
            return;
        }

        app(SignatureManager::class)->revoke($signature);

        Notification::make()
            ->title('Signature revoked')
            ->success()
            ->send();
    }

    /**
     * Every row this user owns — the reusable signature and the document
     * signings it produced — newest first. The same rows the resource's list
     * shows, so the drawer is a replacement for it rather than a subset.
     *
     * @return Collection<int, Signature>
     */
    public function getManagedSignaturesProperty(): Collection
    {
        $userId = $this->currentUserId();

        if (! $userId || ! $this->manageLoaded) {
            return Signature::query()->whereRaw('1 = 0')->get();
        }

        return Signature::query()
            ->where('user_id', $userId)
            ->latest('id')
            ->limit(100)
            ->get();
    }

    public function getManagedSignatureProperty(): ?Signature
    {
        return $this->managing !== null && $this->manageLoaded
            ? $this->ownedSignature($this->managing)?->loadMissing(['user', 'device'])
            : null;
    }

    /**
     * One card per registered PdfTemplate: whether its placement is set up,
     * and where to sign it with this signature or open its designer.
     *
     * Only a reusable signature can be applied to a template; a document
     * signing row has already been used and gets none.
     *
     * @return array<int, array<string, mixed>>
     */
    public function templateCardsFor(Signature $signature): array
    {
        if (! $signature->isPrimary()) {
            return [];
        }

        $cards = [];

        foreach (app(PdfTemplateRegistry::class)->all() as $template) {
            $slots = collect($template->slots());

            $savedKeys = PdfTemplateSlot::query()
                ->where('template_key', $template->key())
                ->whereIn('slot_key', $slots->pluck('key')->all())
                ->pluck('slot_key')
                ->all();

            $requiredKeys = $slots->filter(fn ($slot) => $slot->required)->pluck('key')->all();

            // Clicking a card opens previewMetaUrl in the drawer's own viewer,
            // read-only; nothing here leaves the drawer.
            $cards[] = [
                'key'            => $template->key(),
                'label'          => $template->label(),
                'previewUrl'     => route('signature.pdf-templates.page', ['template' => $template->key(), 'page' => 1]),
                'previewMetaUrl' => route('signature.pdf-templates.preview.meta', ['template' => $template->key()]),
                'slotCount'      => $slots->count(),
                'savedCount'     => count($savedKeys),
                'configured'     => array_diff($requiredKeys, $savedKeys) === [],
            ];
        }

        return $cards;
    }

    protected function ownedSignature(string $uuid): ?Signature
    {
        $userId = $this->currentUserId();

        return $userId
            ? Signature::query()->where('user_id', $userId)->where('uuid', $uuid)->first()
            : null;
    }

    abstract protected function currentUserId(): int|string|null;
}
