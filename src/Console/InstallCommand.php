<?php

namespace Kukux\DigitalSignature\Console;

use Illuminate\Console\Command;
use Kukux\DigitalSignature\Console\Install\InstallContext;
use Kukux\DigitalSignature\Console\Install\InstallStep;
use Kukux\DigitalSignature\Console\Install\StepResult;
use Kukux\DigitalSignature\Console\Install\Steps\CheckRequirements;
use Kukux\DigitalSignature\Console\Install\Steps\CheckStorage;
use Kukux\DigitalSignature\Console\Install\Steps\PublishAssets;
use Kukux\DigitalSignature\Console\Install\Steps\PublishConfig;
use Kukux\DigitalSignature\Console\Install\Steps\RegisterPlugin;
use Kukux\DigitalSignature\Console\Install\Steps\RegisterPolicy;
use Kukux\DigitalSignature\Console\Install\Steps\RunMigration;
use Kukux\DigitalSignature\Console\Install\Steps\UpdateEnv;

/**
 * One command from `composer require` to a migrated, registered package.
 * Every step is safe to rerun: it does only what's missing. See
 * docs/installation.md.
 */
class InstallCommand extends Command
{
    public const DOCS_URL = 'https://cortejojicoy.github.io/digital-signature/';

    protected $signature = 'signature:install
        {--force : Replace the published config, migrate in production, and edit code without asking}
        {--no-migrate : Skip the migration}
        {--no-assets : Skip filament:assets}
        {--no-panel : Skip registering the plugin on your panels}
        {--panel= : Register the plugin on this panel id only}
        {--no-policy : Skip the signature policy}
        {--publish-migrations : Publish the migration to database/migrations instead of running it from vendor/}
        {--agent : Also enable the desktop agent, pinning its server ID and salt}
        {--mode=standalone : standalone, or client for an app whose signatures come from the UPLB Signature hub}
        {--dry-run : Show what each step would do, change nothing}';

    protected $description = 'Install Digital Signature: config, .env, assets, migration, panel plugin and policy';

    public function handle(): int
    {
        $mode = (string) $this->option('mode');

        // Hub mode is the hub's own deployment, set up by hand (docs/hub).
        if (! in_array($mode, ['standalone', 'client'], true)) {
            $this->components->error("--mode must be standalone or client, not \"{$mode}\".");

            return self::FAILURE;
        }

        // Client apps hold no keys and pair no computers: the hub does both.
        if ($mode === 'client' && $this->option('agent')) {
            $this->components->warn('--agent is ignored with --mode=client: the agent pairs with the hub.');
        }

        $context = new InstallContext($this, $this->laravel->basePath(), [
            'mode'               => $mode,
            'force'              => (bool) $this->option('force'),
            'no-migrate'         => (bool) $this->option('no-migrate'),
            'no-assets'          => (bool) $this->option('no-assets'),
            'no-panel'           => (bool) $this->option('no-panel'),
            'panel'              => $this->option('panel'),
            'no-policy'          => (bool) $this->option('no-policy'),
            'publish-migrations' => (bool) $this->option('publish-migrations'),
            'agent'              => (bool) $this->option('agent') && $mode !== 'client',
            'dry-run'            => (bool) $this->option('dry-run'),
            'interactive'        => $this->input->isInteractive(),
        ]);

        $steps = array_values(array_filter($this->steps(), fn (InstallStep $step) => $step->enabled($context)));

        $this->newLine();
        $this->line('  <options=bold>Digital Signature for Filament: installer</>'.($context->dryRun() ? ' <fg=yellow>(dry run, nothing will change)</>' : ''));
        $this->newLine();
        $this->line('  This will:');

        foreach ($steps as $step) {
            $this->line('   • '.$step->summary());
        }

        $this->newLine();

        if (! $context->dryRun() && ! $context->confirm('Continue?')) {
            $this->components->info('Nothing changed.');

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($steps as $step) {
            $result = $step->run($context);
            $this->report($step, $result);

            $failed = $failed || $result->failedStep();

            if ($result->halt) {
                $this->newLine();
                $this->components->error('Install stopped. Fix the problem above and run php artisan signature:install again.');

                return self::FAILURE;
            }
        }

        $this->newLine();

        if ($context->dryRun()) {
            $this->components->info('Dry run finished. Run without --dry-run to apply it.');
        } elseif ($failed) {
            $this->components->warn('Digital Signature is installed, but some steps failed. See above.');
        } else {
            $this->components->info('Digital Signature is installed.');
        }

        $this->line('  Next:');

        foreach (array_unique([...$context->next, 'Docs: '.self::DOCS_URL]) as $next) {
            $this->line('   • '.$next);
        }

        $this->newLine();

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /** @return list<InstallStep> */
    protected function steps(): array
    {
        return [
            new CheckRequirements,
            new PublishConfig,
            new UpdateEnv,
            new PublishAssets,
            new CheckStorage,
            new RunMigration,
            new RegisterPlugin,
            new RegisterPolicy,
        ];
    }

    protected function report(InstallStep $step, StepResult $result): void
    {
        $status = match ($result->status) {
            StepResult::DONE    => '<fg=green;options=bold>DONE</>',
            StepResult::SKIPPED => '<fg=gray;options=bold>SKIPPED</>',
            StepResult::MANUAL  => '<fg=yellow;options=bold>MANUAL</>',
            StepResult::DRY_RUN => '<fg=yellow;options=bold>DRY RUN</>',
            default             => '<fg=red;options=bold>FAILED</>',
        };

        $this->components->twoColumnDetail($step->label(), $status);

        foreach ($result->details as $line) {
            $this->line('    <fg=gray>'.$line.'</>');
        }

        foreach ($result->warnings as $line) {
            $this->line('    <fg=yellow>!</> '.$line);
        }
    }
}
