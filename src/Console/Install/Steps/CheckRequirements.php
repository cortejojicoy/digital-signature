<?php

namespace Kukux\DigitalSignature\Console\Install\Steps;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application;
use Kukux\DigitalSignature\Console\Install\InstallContext;
use Kukux\DigitalSignature\Console\Install\InstallStep;
use Kukux\DigitalSignature\Console\Install\StepResult;
use Kukux\DigitalSignature\Support\FilamentVersion;

class CheckRequirements implements InstallStep
{
    public function label(): string
    {
        return 'Checking requirements';
    }

    public function summary(): string
    {
        return 'check requirements';
    }

    public function enabled(InstallContext $context): bool
    {
        return true;
    }

    public function run(InstallContext $context): StepResult
    {
        $problems = [];
        $found = [];

        if (version_compare(PHP_VERSION, '8.2.0', '<')) {
            $problems[] = 'PHP 8.2+ is required, found '.PHP_VERSION;
        } else {
            $found[] = 'PHP '.PHP_VERSION;
        }

        foreach (['openssl', 'gd'] as $extension) {
            if (extension_loaded($extension)) {
                $found[] = "ext-{$extension}";
            } else {
                $problems[] = "ext-{$extension} is not loaded";
            }
        }

        // composer.json already pins Laravel 12; this is for the log line.
        $found[] = 'Laravel '.Application::VERSION;

        if (! $this->installed('filament/filament')) {
            $problems[] = 'filament/filament is not installed';
        } else {
            $found[] = 'Filament '.($this->version('filament/filament') ?? FilamentVersion::major());
        }

        if ($problems !== []) {
            return StepResult::failed($problems, halt: true);
        }

        $warnings = [];

        if (! $this->installed('barryvdh/laravel-dompdf')) {
            $warnings[] = 'barryvdh/laravel-dompdf not installed (needed for PDF templates)';
            $context->next[] = 'composer require barryvdh/laravel-dompdf   (for PDF templates)';
        }

        if (! extension_loaded('imagick')) {
            $warnings[] = 'ext-imagick not loaded (the placement designer needs it to preview pages)';
        }

        if ($this->installed('bezhansalleh/filament-shield')) {
            $context->next[] = 'php artisan shield:generate --all   (Filament Shield is installed)';
        }

        return StepResult::done([implode(', ', $found)], $warnings);
    }

    protected function installed(string $package): bool
    {
        return class_exists(InstalledVersions::class) && InstalledVersions::isInstalled($package);
    }

    protected function version(string $package): ?string
    {
        $version = InstalledVersions::getPrettyVersion($package);

        return $version === null ? null : ltrim($version, 'v');
    }
}
