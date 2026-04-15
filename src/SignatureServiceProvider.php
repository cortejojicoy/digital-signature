<?php

namespace Kukux\DigitalSignature;

use Illuminate\Support\ServiceProvider;
use Kukux\DigitalSignature\Drivers\Certificates\CfsslDriver;
use Kukux\DigitalSignature\Drivers\Certificates\OpenSslDriver;
use Kukux\DigitalSignature\Drivers\PdfSigners\FpdiDriver;
use Kukux\DigitalSignature\Drivers\PdfSigners\TcpdfDriver;
use Kukux\DigitalSignature\Security\CrlValidator;
use Kukux\DigitalSignature\Security\DocumentIntegrity;
use Kukux\DigitalSignature\Security\DuplicateSignatureGuard;
use Kukux\DigitalSignature\Services\CertificateService;
use Kukux\DigitalSignature\Services\PdfSignerService;
use Kukux\DigitalSignature\Services\SignatureManager;

class SignatureServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/signature.php', 'signature');

        $this->app->singleton(CertificateService::class, function () {
            $driver = match (config('signature.cert_driver')) {
                'cfssl'  => new CfsslDriver(config('signature.cfssl')),
                default  => new OpenSslDriver(config('signature.openssl')),
            };
            return new CertificateService($driver);
        });

        $this->app->singleton(PdfSignerService::class, function () {
            $driver = match (config('signature.pdf_driver')) {
                'tcpdf'  => new TcpdfDriver(),
                default  => new FpdiDriver(),
            };
            return new PdfSignerService($driver);
        });

        // Security services — all stateless, singletons to avoid repeated instantiation
        $this->app->singleton(DuplicateSignatureGuard::class);
        $this->app->singleton(DocumentIntegrity::class);
        $this->app->singleton(CrlValidator::class);

        $this->app->singleton(SignatureManager::class, function ($app) {
            return new SignatureManager(
                $app->make(CertificateService::class),
                $app->make(PdfSignerService::class),
                $app->make(DuplicateSignatureGuard::class),
                $app->make(CrlValidator::class),
                $app->make(DocumentIntegrity::class),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'signature');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/signature.php' => config_path('signature.php'),
            ], 'signature-config');

            $this->publishes([
                __DIR__ . '/../database/migrations' => database_path('migrations'),
            ], 'signature-migrations');

            $this->publishes([
                __DIR__ . '/../resources/views' => resource_path('views/vendor/signature'),
            ], 'signature-views');

            $this->publishes([
                __DIR__ . '/../public' => public_path('vendor/signature'),
            ], 'signature-assets');
        }
    }
}
