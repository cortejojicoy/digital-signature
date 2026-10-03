<?php

namespace Kukux\DigitalSignature\Filament\Pages\Concerns;

use Illuminate\Support\Collection;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Support\LauncherSettings;

/**
 * "Documents I've signed": every signature this user has put on a routed
 * document, newest first, each with the copy they signed and the document as
 * it stands now.
 *
 * The launcher's Signed tab shows the last fifty; this is the full record.
 * A signatory should be able to answer "what exactly did I sign, and when?"
 * without asking whoever routed it. See IsPdfTemplateDesigner for why `$view`
 * is declared by the version subclasses instead of here.
 */
trait IsSignedDocuments
{
    /** Free-text filter over document titles. */
    public string $search = '';

    public int $limit = 100;

    public static function getNavigationIcon(): ?string
    {
        return config('signature.signed.navigation_icon', 'heroicon-o-document-check');
    }

    /** Off the sidebar while the launcher, which links here, is on. */
    public static function shouldRegisterNavigation(): bool
    {
        if (LauncherSettings::replacesNavigation()) {
            return false;
        }

        return (bool) config('signature.signed.navigation', true);
    }

    public static function getNavigationLabel(): string
    {
        return config('signature.signed.navigation_label', 'Signed by me');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('signature.signed.navigation_group', config('signature.inbox.navigation_group'));
    }

    public static function getNavigationSort(): ?int
    {
        $sort = config('signature.signed.navigation_sort');

        return $sort === null ? null : (int) $sort;
    }

    public function showMore(): void
    {
        $this->limit += 100;
    }

    /** @return Collection<int, SignatureRequest> */
    public function getSignedRequestsProperty(): Collection
    {
        $userId = auth()->id();

        if (! $userId) {
            return collect();
        }

        $requests = SignatureRequest::query()
            ->signedFor((int) $userId)
            ->with(['session.signable', 'signature'])
            ->limit($this->limit + 1)
            ->get();

        $needle = mb_strtolower(trim($this->search));

        if ($needle === '') {
            return $requests;
        }

        return $requests->filter(fn (SignatureRequest $request): bool => str_contains(
            mb_strtolower(static::titleOf($request)),
            $needle,
        ))->values();
    }

    public static function titleOf(SignatureRequest $request): string
    {
        $document = $request->session?->signable;

        return $document && method_exists($document, 'getSignableTitle')
            ? $document->getSignableTitle()
            : ($request->session?->template_key ?? 'Document');
    }
}
