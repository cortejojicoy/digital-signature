<?php

namespace Kukux\DigitalSignature\Hub\Filament;

use Filament\Actions\Action;
use Filament\Panel;
use Filament\Support\Assets\Js;
use Filament\Support\Facades\FilamentAsset;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource;
use Kukux\DigitalSignature\Hub\Filament\Pages\Apps;
use Kukux\DigitalSignature\Hub\Filament\Pages\AuditLog;
use Kukux\DigitalSignature\Hub\Filament\Pages\Identify;
use Kukux\DigitalSignature\Hub\Filament\Pages\Landing;
use Kukux\DigitalSignature\Hub\Filament\Pages\PendingClaims;
use Kukux\DigitalSignature\Hub\Filament\Pages\PersonnelDirectoryPage;
use Kukux\DigitalSignature\Hub\Filament\Pages\PersonnelProfile;
use Kukux\DigitalSignature\Hub\Filament\Pages\Profile;
use Kukux\DigitalSignature\Hub\Identity\Http\Controllers\LandingRedirectController;
use Kukux\DigitalSignature\Hub\Identity\Http\Middleware\EnsureIdentified;
use Kukux\DigitalSignature\Hub\Identity\HubRedirector;
use Kukux\DigitalSignature\SignaturePlugin;
use Kukux\DigitalSignature\Support\FilamentVersion;
use LogicException;

/**
 * Hub mode: what SignaturePlugin::hubPersonPanel() and hubAdminPanel()
 * register on their panel (plan 1.8). See docs/hub/panels.md.
 *
 *   person (id `hub`, path `/`)   no topbar, no sidebar: Landing (its login
 *                                 page), Who are you?, Profile (home). No
 *                                 launcher, inbox or Signatures resource.
 *   admin  (id `admin`, `/admin`) default Filament layout: Personnel, Pending
 *                                 claims, Audit log, Apps, Signatures. No
 *                                 login page: guests go to the landing.
 *
 * The panel ids are remembered here, so URLs and access checks follow
 * whatever ids the host gave its panels.
 */
final class HubPanels
{
    private static string $personPanelId = 'hub';

    private static string $adminPanelId = 'admin';

    public static function register(Panel $panel, SignaturePlugin $plugin): void
    {
        // The pages declare Filament 4's instance $view (Filament 3's is
        // static, so loading one there is a fatal error), and the person
        // panel relies on ->topbar(false), which Filament 3 doesn't have.
        if ($plugin->getHubPanel() !== null && FilamentVersion::major() < 4) {
            throw new LogicException('The hub panels need Filament 4 or 5; this app runs Filament '.FilamentVersion::major().'. See docs/hub/panels.md.');
        }

        match ($plugin->getHubPanel()) {
            'person' => self::person($panel),
            'admin'  => self::admin($panel, $plugin),
            default  => null,
        };
    }

    public static function personPanelId(): string
    {
        return self::$personPanelId;
    }

    public static function adminPanelId(): string
    {
        return self::$adminPanelId;
    }

    private static function person(Panel $panel): void
    {
        self::$personPanelId = $panel->getId();

        $panel
            ->login(Landing::class)
            // A single centred page that reads like a hub, not an admin tool.
            // The host's provider says the same; repeating it here keeps the
            // preset right even if it doesn't.
            ->topbar(false)
            ->navigation(false)
            ->pages([Profile::class, Identify::class])
            ->authMiddleware([EnsureIdentified::class]);

        self::registerAssets();
    }

    private static function admin(Panel $panel, SignaturePlugin $plugin): void
    {
        self::$adminPanelId = $panel->getId();

        $panel
            // No password form: guests sign in with their computer on the
            // person panel's landing, then come back (intended URL).
            ->login(LandingRedirectController::class)
            ->sidebarCollapsibleOnDesktop()
            ->pages([
                PersonnelDirectoryPage::class,
                PersonnelProfile::class,
                PendingClaims::class,
                AuditLog::class,
                Apps::class,
            ])
            ->userMenuItems([
                'hub-profile' => Action::make('hub-profile')
                    ->label('My profile')
                    ->icon('heroicon-o-identification')
                    ->url(fn (): string => app(HubRedirector::class)->profileUrl()),
            ]);

        if (config('signature.resource.enabled', true)) {
            $panel->resources([SignatureResource::class]);
        }

        self::registerAssets();
    }

    /** The signature pad (and the Signatures resource) are React islands in the plugin bundle. */
    private static function registerAssets(): void
    {
        FilamentAsset::register([
            Js::make('signature-plugin', __DIR__.'/../../../resources/dist/digital-signature.js'),
        ], 'kukux/digital-signature');
    }
}
