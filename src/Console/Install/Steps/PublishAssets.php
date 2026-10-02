<?php

namespace Kukux\DigitalSignature\Console\Install\Steps;

use Illuminate\Support\Facades\Artisan;
use Kukux\DigitalSignature\Console\Install\InstallContext;
use Kukux\DigitalSignature\Console\Install\InstallStep;
use Kukux\DigitalSignature\Console\Install\StepResult;

class PublishAssets implements InstallStep
{
    public function label(): string
    {
        return 'Publishing assets';
    }

    public function summary(): string
    {
        return 'publish the Filament assets';
    }

    public function enabled(InstallContext $context): bool
    {
        return ! $context->option('no-assets');
    }

    public function run(InstallContext $context): StepResult
    {
        if (! array_key_exists('filament:assets', Artisan::all())) {
            return StepResult::manual(['filament:assets isn\'t available. Run it once Filament is set up.']);
        }

        if ($context->dryRun()) {
            return StepResult::dryRun(['would run php artisan filament:assets']);
        }

        if ($context->call('filament:assets') !== 0) {
            return StepResult::failed(['php artisan filament:assets failed. Run it yourself to see why.']);
        }

        $context->next[] = 'Rerun php artisan filament:assets after every composer update of this package';

        return StepResult::done();
    }
}
