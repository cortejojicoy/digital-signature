<?php

namespace Kukux\DigitalSignature\Filament\Pages\Concerns;

use Kukux\DigitalSignature\Filament\Concerns\ActsOnSignatureRequests;
use Kukux\DigitalSignature\Support\LauncherSettings;

/**
 * "Documents waiting for my signature."
 *
 * This is the counterpart to auto-affix, and the reason the default consent
 * model is safe: a signatory never has to hunt for the document, but the
 * signature itself is still produced inside their own authenticated request,
 * with their own certificate.
 *
 * The sign/decline behaviour lives in ActsOnSignatureRequests, shared with the
 * floating launcher's slide-over. What remains here is this surface's
 * navigation identity. See IsPdfTemplateDesigner for why `$view` is declared
 * by the version subclasses instead of here.
 */
trait IsSignatureInbox
{
    use ActsOnSignatureRequests;

    /**
     * Declared as a getter rather than a $navigationIcon property: v3 types
     * that property ?string while v4/v5 widened it to BackedEnum|string|null,
     * and a property type cannot satisfy both. A narrowed *return* type is
     * legal covariance on every version.
     */
    public static function getNavigationIcon(): ?string
    {
        return config('signature.inbox.navigation_icon', 'heroicon-o-inbox-arrow-down');
    }

    /**
     * The floating launcher opens this same queue in a slide-over. When it's
     * on, the page stays routable — the slide-over links to it for the full
     * view — but it stops claiming a sidebar item, because two entry points to
     * one queue is clutter rather than convenience.
     */
    public static function shouldRegisterNavigation(): bool
    {
        if (LauncherSettings::replacesNavigation()) {
            return false;
        }

        return (bool) config('signature.inbox.navigation', true);
    }

    public static function getNavigationLabel(): string
    {
        return config('signature.inbox.navigation_label', 'Awaiting my signature');
    }

    public static function getNavigationGroup(): ?string
    {
        return config('signature.inbox.navigation_group');
    }

    public static function getNavigationSort(): ?int
    {
        $sort = config('signature.inbox.navigation_sort');

        return $sort === null ? null : (int) $sort;
    }

    /**
     * Badge showing how many documents are waiting, so the signatory sees
     * the queue without opening the page.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = static::outstandingCountFor(auth()->id());

        return $count > 0 ? (string) $count : null;
    }
}
