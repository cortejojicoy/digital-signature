<?php

namespace Kukux\DigitalSignature;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Kukux\DigitalSignature\Drivers\Certificates\CfsslDriver;
use Kukux\DigitalSignature\Drivers\Certificates\OpenSslDriver;
use Kukux\DigitalSignature\Drivers\PdfSigners\FpdiDriver;
use Kukux\DigitalSignature\Drivers\PdfSigners\TcpdfDriver;
use Kukux\DigitalSignature\Filament\Actions\ActionResolver;
use Kukux\DigitalSignature\Filament\Actions\HeaderActionResolver;
use Kukux\DigitalSignature\Filament\Actions\RequestSignaturesResolver;
use Kukux\DigitalSignature\Filament\Actions\RequestSignaturesTableResolver;
use Kukux\DigitalSignature\Filament\Pages\PdfTemplateDesignerResolver;
use Kukux\DigitalSignature\Filament\Pages\PdfTemplateSignerResolver;
use Kukux\DigitalSignature\Filament\Pages\SignatureInboxResolver;
use Kukux\DigitalSignature\Filament\Resources\ResourceResolver;
use Kukux\DigitalSignature\Filament\Livewire\SignatureLauncher;
use Kukux\DigitalSignature\Filament\Resources\SignatureResource\ViewSignatureResolver;
use Kukux\DigitalSignature\Http\Controllers\DeviceFingerprintController;
use Kukux\DigitalSignature\Http\Controllers\PdfTemplateDesignerController;
use Kukux\DigitalSignature\Http\Controllers\PdfTemplateSignerController;
use Kukux\DigitalSignature\Http\Controllers\SignatureAssetController;
use Kukux\DigitalSignature\Security\CrlValidator;
use Kukux\DigitalSignature\Security\DocumentIntegrity;
use Kukux\DigitalSignature\Security\DuplicateSignatureGuard;
use Kukux\DigitalSignature\Security\PngMetaEmbedder;
use Kukux\DigitalSignature\Security\SignatureMetadataService;
use Kukux\DigitalSignature\Drivers\PdfSigners\Contracts\PdfSignerDriver;
use Kukux\DigitalSignature\Services\AutoAffixService;
use Kukux\DigitalSignature\Services\CertificateService;
use Kukux\DigitalSignature\Pdf\PdfPageRasterizer;
use Kukux\DigitalSignature\Services\PdfSignerService;
use Kukux\DigitalSignature\Services\PdfTemplateRegistry;
use Kukux\DigitalSignature\Services\SignatoryRouter;
use Kukux\DigitalSignature\Services\SignatureManager;
use Kukux\DigitalSignature\Services\SigningSessionManager;
use Kukux\DigitalSignature\Signatories\SignatoryResolverFactory;
use Livewire\Livewire;

class SignatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/signature.php', 'signature');

        // Alias every version-split component to its v3 or v4/v5 implementation
        // BEFORE anything references a canonical name (panel registration, the
        // plugin, host-app imports). Each of these classes has a base class or a
        // property that changed between Filament majors — see
        // Filament\Support\ComponentResolver and docs/signatory-routing.md §8.
        foreach ([
            ResourceResolver::class,
            ViewSignatureResolver::class,
            ActionResolver::class,
            HeaderActionResolver::class,
            RequestSignaturesResolver::class,
            RequestSignaturesTableResolver::class,
            PdfTemplateDesignerResolver::class,
            PdfTemplateSignerResolver::class,
            SignatureInboxResolver::class,
        ] as $resolver) {
            $resolver::registerAlias();
        }

        $this->app->singleton(CertificateService::class, function () {
            $driver = match (config('signature.cert_driver')) {
                'cfssl'  => new CfsslDriver(config('signature.cfssl')),
                default  => new OpenSslDriver(config('signature.openssl')),
            };
            return new CertificateService($driver);
        });

        // Bound separately from the service so callers can inspect the driver's
        // capabilities — SigningSessionManager asks whether it can do true
        // incremental (PAdES) signing before accepting that mode.
        $this->app->singleton(PdfSignerDriver::class, function () {
            return match (config('signature.pdf_driver')) {
                'tcpdf'  => new TcpdfDriver(),
                default  => new FpdiDriver(),
            };
        });

        $this->app->singleton(PdfSignerService::class, function ($app) {
            return new PdfSignerService($app->make(PdfSignerDriver::class));
        });

        // Security services
        $this->app->singleton(DuplicateSignatureGuard::class);
        $this->app->singleton(DocumentIntegrity::class);
        $this->app->singleton(CrlValidator::class);
        $this->app->singleton(PngMetaEmbedder::class);

        // SignatureMetadataService needs the current Request — bind as scoped
        // so it gets a fresh instance per HTTP request (correct IP / UA).
        $this->app->scoped(SignatureMetadataService::class, function ($app) {
            return new SignatureMetadataService(
                $app->make(PngMetaEmbedder::class),
                $app->make('request'),
            );
        });

        $this->app->singleton(PdfTemplateRegistry::class);

        // Signatory routing. The factory is a singleton because the plugin's
        // resolveSignatoriesUsing() override is registered onto it at panel
        // boot and must be visible to every later lookup.
        $this->app->singleton(SignatoryResolverFactory::class);

        $this->app->singleton(SignatoryRouter::class, function ($app) {
            return new SignatoryRouter(
                $app->make(PdfTemplateRegistry::class),
                $app->make(SignatoryResolverFactory::class),
            );
        });

        $this->app->singleton(SigningSessionManager::class, function ($app) {
            return new SigningSessionManager(
                $app->make(PdfTemplateRegistry::class),
                $app->make(SignatoryRouter::class),
                $app->make(SignatureManager::class),
                $app->make(DocumentIntegrity::class),
            );
        });

        $this->app->singleton(AutoAffixService::class, function ($app) {
            return new AutoAffixService($app->make(SigningSessionManager::class));
        });

        $this->app->singleton(PdfPageRasterizer::class, function () {
            return new PdfPageRasterizer(
                dpi: (int) config('signature.designer.dpi', 144),
            );
        });

        $this->app->singleton(SignatureManager::class, function ($app) {
            return new SignatureManager(
                $app->make(CertificateService::class),
                $app->make(PdfSignerService::class),
                $app->make(DuplicateSignatureGuard::class),
                $app->make(CrlValidator::class),
                $app->make(DocumentIntegrity::class),
                $app->make(SignatureMetadataService::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'signature');

        // The floating launcher. Registered here rather than in the plugin so
        // the component resolves on any panel the render hook fires for, and
        // so a host that mounts <livewire:kukux-digital-signature.launcher />
        // in its own layout can do so without the plugin.
        if (class_exists(Livewire::class)) {
            Livewire::component('kukux-digital-signature.launcher', SignatureLauncher::class);
        }

        // Register templates declared in config. Runtime registration via the
        // plugin or container can add more on top of this baseline.
        $configured = (array) config('signature.templates', []);
        if ($configured !== []) {
            $this->app->make(PdfTemplateRegistry::class)->registerMany($configured);
        }

        // Route for receiving the browser device fingerprint and storing it in session
        Route::post('/signature/device-fingerprint', [DeviceFingerprintController::class, 'store'])
            ->middleware(['web'])
            ->name('signature.device-fingerprint');

        // Signed-URL endpoint that streams signature images from the (typically
        // private) storage disk. The `signed` middleware enforces the URL's
        // HMAC + expiry — see Signature::getTemporaryImageUrl() for the
        // generator side.
        Route::get('/signature/assets/{digitalSignature:uuid}', [SignatureAssetController::class, 'show'])
            ->middleware(['web', 'signed'])
            ->name('signature.asset');

        // Placement-designer + signer endpoints.
        //
        // We use only the `web` middleware (not `auth`) because the bare
        // `auth` middleware uses Laravel's default guard, which often
        // differs from the Filament panel's guard. When they differ, an
        // already-logged-in panel user gets redirected to a panel login
        // route that may not exist under that name → 500 with
        // "Route [filament.<panel>.auth.login] not defined".
        //
        // Authorization is enforced inside the controllers via
        // `auth()->id()` checks on the records (signatures must be
        // owned by the current user). The `web` group is sufficient to
        // load the session so `auth()` resolves correctly.
        Route::prefix('signature/pdf-templates')
            ->middleware(['web'])
            ->name('signature.pdf-templates.')
            ->group(function () {
                Route::get('{template}/meta', [PdfTemplateDesignerController::class, 'meta'])
                    ->name('meta');
                Route::get('{template}/pages/{page}', [PdfTemplateDesignerController::class, 'page'])
                    ->whereNumber('page')
                    ->name('page');
                Route::post('{template}/slots/{slot}', [PdfTemplateDesignerController::class, 'save'])
                    ->name('slot.save');

                // End-user signer endpoints. Same prefix because they
                // operate on the same template surface; subroutes are
                // scoped by signature uuid so the URL itself encodes who
                // is signing.
                Route::get('{template}/sign/{signature}/meta', [PdfTemplateSignerController::class, 'meta'])
                    ->name('signer.meta');
                Route::post('{template}/sign/{signature}/finalize', [PdfTemplateSignerController::class, 'finalize'])
                    ->name('signer.finalize');
            });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/signature.php' => config_path('signature.php'),
            ], 'signature-config');

            $this->publishes($this->migrationPublishMap(), 'signature-migrations');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/signature'),
            ], 'signature-views');

            // Publish the bundled JS to public/vendor/digital-signature/.
            // Filament's own asset pipeline (php artisan filament:assets) handles
            // this automatically via FilamentAsset::register() in SignaturePlugin —
            // this publish tag is the manual fallback for projects that don't run
            // filament:assets (e.g. when serving the JS directly without the
            // panel's bundler integration).
            $this->publishes([
                __DIR__ . '/../resources/dist' => public_path('vendor/digital-signature'),
            ], 'signature-assets');
        }
    }

    /**
     * Build a source-to-destination map for publishing migrations.
     *
     * Each migration is stamped with a fresh, sequential timestamp at publish
     * time so the files (a) don't collide with the host app's existing
     * migrations and (b) run after them. If a host already has a published
     * copy (matched by suffix), the existing destination is reused so
     * re-publishing is idempotent.
     */
    protected function migrationPublishMap(): array
    {
        $sourceDir = __DIR__ . '/../database/migrations';
        $files = glob($sourceDir . '/*.php') ?: [];
        sort($files);

        $map = [];
        $timestamp = time();

        foreach ($files as $index => $sourcePath) {
            $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', basename($sourcePath));

            $existing = glob(database_path('migrations/*_' . $suffix)) ?: [];
            if ($existing !== []) {
                $map[$sourcePath] = $existing[0];
                continue;
            }

            $stamp = date('Y_m_d_His', $timestamp + $index);
            $map[$sourcePath] = database_path('migrations/' . $stamp . '_' . $suffix);
        }

        return $map;
    }
}
