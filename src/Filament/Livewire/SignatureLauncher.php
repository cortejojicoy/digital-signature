<?php

namespace Kukux\DigitalSignature\Filament\Livewire;

use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Kukux\DigitalSignature\Filament\Concerns\ActsOnSignatureRequests;
use Kukux\DigitalSignature\Filament\Pages\SignatureInbox;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Support\LauncherSettings;
use Livewire\Component;
use Throwable;

/**
 * The floating launcher: one button on every panel page that opens a
 * slide-over with the documents waiting on the signed-in user.
 *
 * Signing is an interruption, not a destination. A signatory is somewhere else
 * in the app when a document reaches them, and making them leave that page,
 * find a sidebar item under whatever navigation group the host app chose, act,
 * and navigate back is most of the friction in a signing flow. So the queue
 * comes to the page instead.
 *
 * Behaviour comes from ActsOnSignatureRequests, shared with the full-page
 * inbox: same query, same ownership checks, same exception handling, and the
 * signature is still produced inside this user's own authenticated request.
 * The launcher is a different door into one room, not a second room.
 *
 * The request list loads on first open rather than with the page. A panel-wide
 * render hook runs on *every* page, and eagerly loading a list plus its
 * signable relations there would put that cost on requests where nobody opens
 * the panel. The unread count is a single COUNT and is cheap enough to render
 * eagerly, because that is the part that has to be visible without a click.
 */
class SignatureLauncher extends Component
{
    use ActsOnSignatureRequests;

    /** Set on first open; until then the slide-over renders its skeleton. */
    public bool $loaded = false;

    public function loadRequests(): void
    {
        $this->loaded = true;
    }

    // -------------------------------------------------------------------------
    // Data
    // -------------------------------------------------------------------------

    public function getCountProperty(): int
    {
        return static::outstandingCountFor($this->currentUserId());
    }

    /**
     * The signed-in user, per the *panel's* guard where there is one.
     *
     * Panels routinely authenticate against a guard other than the app
     * default, so asking the panel first is what makes the launcher show the
     * right queue in a multi-guard app. Filament::auth() needs a current or
     * default panel to resolve a guard, though, and this component is also
     * mountable outside a panel (a host layout, a test), so the app guard is
     * the fallback rather than an error.
     */
    protected function currentUserId(): int|string|null
    {
        try {
            $id = Filament::auth()->id();
        } catch (Throwable) {
            $id = null;
        }

        return $id ?? auth()->id();
    }

    /**
     * The signer's own signature library. Empty means they have nothing to
     * sign *with*, which is the single most common reason a routed document
     * stalls — so the slide-over says so and links to registration rather than
     * showing a Sign button that could only fail.
     *
     * @return Collection<int, Signature>
     */
    public function getSignaturesProperty(): Collection
    {
        $userId = $this->currentUserId();

        if (! $userId) {
            return Signature::query()->whereRaw('1 = 0')->get();
        }

        return Signature::query()
            ->where('user_id', $userId)
            ->whereNull('signable_id')
            ->where('status', 'active')
            ->latest('id')
            ->limit(4)
            ->get();
    }

    // -------------------------------------------------------------------------
    // Links out
    //
    // Each target is optional: a host can register the plugin with
    // ->withoutResource() or ->withoutInbox(), and getUrl() throws when the
    // page or resource isn't on this panel. A missing link should hide a row
    // in the footer, never break the launcher on every page of the app.
    // -------------------------------------------------------------------------

    public function getInboxUrlProperty(): ?string
    {
        return $this->safeUrl(fn () => SignatureInbox::getUrl());
    }

    public function getLibraryUrlProperty(): ?string
    {
        return $this->safeUrl(fn () => SignatureResource::getUrl());
    }

    public function getRegisterUrlProperty(): ?string
    {
        return $this->safeUrl(fn () => SignatureResource::getUrl('create'));
    }

    protected function safeUrl(callable $resolver): ?string
    {
        try {
            $url = $resolver();
        } catch (Throwable) {
            return null;
        }

        return is_string($url) && $url !== '' ? $url : null;
    }

    // -------------------------------------------------------------------------
    // Appearance
    // -------------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public function getSettingsProperty(): array
    {
        return [
            'position'      => LauncherSettings::position(),
            'icon'          => LauncherSettings::icon(),
            'label'         => LauncherSettings::label(),
            'color'         => LauncherSettings::color(),
            'poll'          => LauncherSettings::pollSeconds(),
            'hideWhenEmpty' => LauncherSettings::hideWhenEmpty(),
            'offsetX'       => LauncherSettings::offsetX(),
            'offsetY'       => LauncherSettings::offsetY(),
            'zIndex'        => LauncherSettings::zIndex(),
        ];
    }

    /**
     * What the browser-side placement pass needs to keep the button clear of
     * whatever the host app has already pinned in this corner.
     *
     * @return array<string, mixed>
     */
    public function getPlacementProperty(): array
    {
        return [
            'enabled'  => LauncherSettings::avoidOverlap(),
            'position' => LauncherSettings::position(),
            'gap'      => LauncherSettings::gap(),
            'avoid'    => LauncherSettings::avoidSelectors(),
            'ignore'   => LauncherSettings::ignoreSelectors(),
        ];
    }

    public function render(): View
    {
        return view('signature::filament.livewire.signature-launcher');
    }
}
