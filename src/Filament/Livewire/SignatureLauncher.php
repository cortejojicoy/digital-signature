<?php

namespace Kukux\DigitalSignature\Filament\Livewire;

use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as BaseCollection;
use Kukux\DigitalSignature\Filament\Concerns\ActsOnSignatureRequests;
use Kukux\DigitalSignature\Filament\Concerns\ManagesSignatures;
use Kukux\DigitalSignature\Filament\Concerns\RegistersSignatures;
use Kukux\DigitalSignature\Filament\Pages\SignatureInbox;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SignatureRequest;
use Kukux\DigitalSignature\Models\UserPreference;
use Kukux\DigitalSignature\Support\LauncherSettings;
use Kukux\DigitalSignature\Support\ViewerAssets;
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
    use ManagesSignatures;
    use RegistersSignatures;

    /** Set on first open; until then the slide-over renders its skeleton. */
    public bool $loaded = false;

    /** Draw-pad output for the library tab's "add a signature" form. */
    public ?string $newSignature = null;

    public ?string $newCertificatePassword = null;

    /**
     * "All documents I've signed": the full record, opened as its own wide
     * drawer view like Manage signatures. Deferred until first opened, for the
     * same reason the queue is.
     */
    public bool $signedLoaded = false;

    public string $signedSearch = '';

    public int $signedLimit = 100;

    /** This request's preferences, loaded once — see preferences(). */
    protected ?array $preferences = null;

    public function loadRequests(): void
    {
        $this->loaded = true;
    }

    public function openSigned(): void
    {
        $this->loaded = true;
        $this->signedLoaded = true;
    }

    public function showMoreSigned(): void
    {
        $this->signedLimit += 100;
    }

    /**
     * Save where this user wants their launcher. The browser has already
     * moved the button; this makes it stick across pages and devices.
     * Anything invalid is dropped rather than stored, so a bad value can
     * never reach a style attribute later.
     */
    public function saveLauncherPlacement(string $position, int $offsetX, int $offsetY): void
    {
        $userId = $this->currentUserId();

        if (! $userId || ! LauncherSettings::customizable()) {
            return;
        }

        if (! in_array($position, LauncherSettings::POSITIONS, true)) {
            return;
        }

        $clamp = static fn (int $px): string => max(0, min(400, $px)).'px';

        $row = UserPreference::query()->firstOrNew(['user_id' => $userId]);
        $preferences = is_array($row->preferences) ? $row->preferences : [];

        $preferences['launcher'] = [
            'position' => $position,
            'offset_x' => $clamp($offsetX),
            'offset_y' => $clamp($offsetY),
        ];

        $row->preferences = $preferences;
        $row->save();

        $this->preferences = $preferences;
    }

    /** Forget this user's placement, so the config default applies again. */
    public function resetLauncherPlacement(): void
    {
        $userId = $this->currentUserId();

        if (! $userId) {
            return;
        }

        $row = UserPreference::query()->where('user_id', $userId)->first();

        if ($row === null) {
            return;
        }

        $preferences = is_array($row->preferences) ? $row->preferences : [];
        unset($preferences['launcher']);

        $row->preferences = $preferences;
        $row->save();

        $this->preferences = $preferences;
    }

    /**
     * Register a signature without leaving the page.
     *
     * The rules live in RegistersSignatures, shared with the Signatures
     * resource — see that trait for why they are not duplicated here.
     */
    public function createSignature(): void
    {
        $signature = $this->registerSignature($this->newSignature, $this->newCertificatePassword);

        // The password is a signing credential and the drawing is a partial
        // one. Neither should survive in component state, and a failed attempt
        // should not clear what the user drew and make them draw it again.
        $this->newCertificatePassword = null;

        if ($signature !== null) {
            $this->newSignature = null;
        }
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
            ->limit(12)
            ->get();
    }

    /**
     * URL templates and asset URLs the drawer's document pane needs.
     *
     * Templates rather than per-request URLs: the pane is opened by Alpine
     * without a round trip, so the ids are only known in the browser. `__ID__`
     * follows the same convention as the designer's `__PAGE__`.
     *
     * @return array<string, string>
     */
    public function getViewerProperty(): array
    {
        return [
            'metaUrlTemplate' => route('signature.request.meta', ['signatureRequest' => '__ID__']),
            'signUrlTemplate' => route('signature.request.sign', ['signatureRequest' => '__ID__']),
            'bundleSrc'       => ViewerAssets::bundleUrl(),
            'workerSrc'       => ViewerAssets::workerUrl(),
        ];
    }

    // -------------------------------------------------------------------------
    // Links out
    //
    // Each target is optional: a host can register the plugin with
    // ->withoutResource() or ->withoutInbox(), and getUrl() throws when the
    // page or resource isn't on this panel. A missing link should hide a row
    // in the footer, never break the launcher on every page of the app.
    // -------------------------------------------------------------------------

    /**
     * Everything this user has signed, newest first, for the "All documents
     * I've signed" view. One more row than the limit is fetched so the view
     * knows whether to offer "Show more".
     *
     * The search runs over document titles in PHP, because a title comes from
     * the host's signable (getSignableTitle()) rather than a column, so it
     * filters what has been loaded so far.
     *
     * @return BaseCollection<int, SignatureRequest>
     */
    public function getAllSignedProperty(): BaseCollection
    {
        $userId = $this->currentUserId();

        if (! $userId || ! $this->signedLoaded) {
            return collect();
        }

        $requests = SignatureRequest::query()
            ->signedFor((int) $userId)
            ->with(['session.signable'])
            ->limit($this->signedLimit + 1)
            ->get();

        $needle = mb_strtolower(trim($this->signedSearch));

        if ($needle === '') {
            return $requests;
        }

        return $requests
            ->filter(fn (SignatureRequest $request): bool => str_contains(mb_strtolower(static::titleOf($request)), $needle))
            ->values();
    }

    public static function titleOf(SignatureRequest $request): string
    {
        $document = $request->session?->signable;

        return $document && method_exists($document, 'getSignableTitle')
            ? $document->getSignableTitle()
            : ($request->session?->template_key ?? 'Document');
    }

    public function getInboxUrlProperty(): ?string
    {
        return $this->safeUrl(fn () => SignatureInbox::getUrl());
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
        $preferences = $this->preferences();

        return [
            'position'      => LauncherSettings::position($preferences),
            'icon'          => LauncherSettings::icon(),
            'label'         => LauncherSettings::label(),
            'color'         => LauncherSettings::color(),
            'poll'          => LauncherSettings::pollSeconds(),
            'hideWhenEmpty' => LauncherSettings::hideWhenEmpty(),
            'width'         => LauncherSettings::width(),
            'manageWidth'   => LauncherSettings::manageWidth(),
            'offsetX'       => LauncherSettings::offsetX($preferences),
            'offsetY'       => LauncherSettings::offsetY($preferences),
            'zIndex'        => LauncherSettings::zIndex(),
            'customizable'  => LauncherSettings::customizable(),
        ];
    }

    /**
     * What the Settings tab starts from: the placement in effect, as slider
     * values, plus the config default that "Reset" goes back to.
     *
     * @return array<string, mixed>
     */
    public function getPlacementChoiceProperty(): array
    {
        $preferences = $this->preferences();

        return [
            'position' => LauncherSettings::position($preferences),
            'x'        => LauncherSettings::toPixels(LauncherSettings::offsetX($preferences)),
            'y'        => LauncherSettings::toPixels(LauncherSettings::offsetY($preferences)),
            'default'  => [
                'position' => LauncherSettings::defaultPosition(),
                'x'        => LauncherSettings::toPixels(LauncherSettings::offsetX()),
                'y'        => LauncherSettings::toPixels(LauncherSettings::offsetY()),
            ],
        ];
    }

    /**
     * The signed-in user's saved preferences, read once per request: the
     * launcher renders on every panel page and re-renders on each poll, and
     * settings, placement and the Settings tab all need them.
     *
     * @return array<string, mixed>
     */
    protected function preferences(): array
    {
        return $this->preferences ??= UserPreference::for($this->currentUserId());
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
            'position' => LauncherSettings::position($this->preferences()),
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
