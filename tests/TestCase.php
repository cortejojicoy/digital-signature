<?php

namespace Kukux\DigitalSignature\Tests;

use Filament\FilamentServiceProvider;
use Illuminate\Support\Facades\Schema;
use Kukux\DigitalSignature\SignatureServiceProvider;
use Livewire\LivewireServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [
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
        ]);
        $app['config']->set('signature.cert_driver', 'openssl');
        $app['config']->set('signature.pdf_driver', 'fpdi');
        $app['config']->set('signature.storage_disk', 'testing');
        $app['config']->set('filesystems.disks.testing', [
            'driver' => 'local',
            'root' => storage_path('framework/testing/disks/signature'),
        ]);
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

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }
}
