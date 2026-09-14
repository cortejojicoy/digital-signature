<?php

namespace Kukux\DigitalSignature\Tests;

use BladeUI\Heroicons\BladeHeroiconsServiceProvider;
use BladeUI\Icons\BladeIconsServiceProvider;
use Filament\FilamentServiceProvider;
use Filament\Support\SupportServiceProvider;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ViewErrorBag;
use Kukux\DigitalSignature\SignatureServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
            // Order matters, and not cosmetically. Filament's support provider
            // rebinds Livewire's DataStore to its own subclass with bind()
            // rather than singleton(), which drops any already-registered
            // instance; Livewire's mechanisms must therefore register *after*
            // it, or every resolve hands back a fresh store and rendering any
            // Livewire component dies on a null error bag. Composer's
            // discovery order gives a real app the same sequence.
            // Icon sets the Filament Blade components render through.
            BladeIconsServiceProvider::class,
            BladeHeroiconsServiceProvider::class,
            SupportServiceProvider::class,
            LivewireServiceProvider::class,
            FilamentServiceProvider::class,
            SignatureServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        @mkdir(storage_path('framework/testing'), 0777, true);
        putenv('RANDFILE='.storage_path('framework/testing/.rnd'));

        $app['config']->set('app.key', 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            // SQLite ignores foreign keys unless asked. Enforcing them here
            // means a migration or a service that writes a dangling reference
            // fails in the suite instead of in a host app on MySQL.
            'foreign_key_constraints' => true,
        ]);
        $app['config']->set('signature.cert_driver', 'openssl');
        $app['config']->set('signature.pdf_driver', 'fpdi');
        $app['config']->set('signature.storage_disk', 'testing');
        $app['config']->set('auth.providers.users.model', \Kukux\DigitalSignature\Tests\Support\TestUser::class);
        // Test-only Blade views live under tests/ rather than resources/views,
        // so they aren't shipped to host apps by `vendor:publish`.
        $app['config']->set('view.paths', array_merge(
            (array) $app['config']->get('view.paths', []),
            [__DIR__.'/views'],
        ));

        $app['config']->set('filesystems.disks.testing', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/signature'),
        ]);

        // In a panel the `web` middleware group shares this via
        // ShareErrorsFromSession; Livewire's validation support assumes it is
        // present whenever a component renders. Without it, rendering any
        // Livewire component in the suite dies on a null error bag.
        $app['view']->share('errors', new ViewErrorBag);
    }

    protected function defineDatabaseMigrations(): void
    {
        // Minimal users table required by foreign keys
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->timestamps();
        });

        // Host-app table the signatory-routing tests route against.
        Schema::create('accomplishment_reports', function ($table) {
            $table->id();
            $table->string('title')->nullable();
            $table->unsignedBigInteger('prepared_by_id')->nullable();
            $table->unsignedBigInteger('attested_by_id')->nullable();
            $table->unsignedBigInteger('noted_by_id')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
