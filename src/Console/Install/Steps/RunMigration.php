<?php

namespace Kukux\DigitalSignature\Console\Install\Steps;

use Illuminate\Database\Migrations\Migrator;
use Kukux\DigitalSignature\Console\Install\InstallContext;
use Kukux\DigitalSignature\Console\Install\InstallStep;
use Kukux\DigitalSignature\Console\Install\StepResult;
use Throwable;

class RunMigration implements InstallStep
{
    /** The package migration, without its timestamp. */
    public const MIGRATION = 'create_digital_signature_tables';

    public function label(): string
    {
        return 'Running migration';
    }

    public function summary(): string
    {
        return 'run the package migration';
    }

    public function enabled(InstallContext $context): bool
    {
        return ! $context->option('no-migrate');
    }

    public function run(InstallContext $context): StepResult
    {
        $notes = [];

        if ($this->hasLegacyMigrations($context)) {
            $notes[] = 'Found migrations from an earlier release. The new one adopts their tables instead of recreating them.';
        }

        if ($context->option('publish-migrations')) {
            return $this->publishAndRun($context, $notes);
        }

        try {
            $pending = $this->pending($context);
        } catch (Throwable $e) {
            return StepResult::failed(['Couldn\'t reach the database: '.$e->getMessage()]);
        }

        if ($pending === []) {
            return StepResult::skipped('nothing to migrate', $notes);
        }

        $details = array_keys($pending);
        $others = array_filter($details, fn (string $name) => ! str_ends_with($name, '_'.self::MIGRATION));

        if ($others !== []) {
            $notes[] = 'This also runs '.count($others).' pending migration(s) from your app, listed above.';
        }

        if ($context->dryRun()) {
            return StepResult::dryRun(array_map(fn ($name) => "would run {$name}", $details));
        }

        if ($context->isProduction() && ! $context->force()) {
            return StepResult::manual(array_merge(
                ['This is production, so the migration wasn\'t run. Review it, then: php artisan migrate --force'],
                $details,
            ), $notes);
        }

        if (! $context->confirm('Run '.count($details).' migration(s)?')) {
            $context->next[] = 'php artisan migrate';

            return StepResult::skipped('you chose not to migrate yet', $notes);
        }

        return $this->migrate($context, [], $details, $notes);
    }

    /**
     * --publish-migrations: copy the migration into database/migrations and
     * run only that copy. The package's own copy was already registered for
     * this process, so a plain `migrate` would see both.
     *
     * @param  list<string>  $notes
     */
    protected function publishAndRun(InstallContext $context, array $notes): StepResult
    {
        if ($context->dryRun()) {
            return StepResult::dryRun(['would publish the migration to database/migrations and run it']);
        }

        if ($context->call('vendor:publish', ['--tag' => 'signature-migrations']) !== 0) {
            return StepResult::failed(['vendor:publish --tag=signature-migrations failed']);
        }

        $published = glob($context->path('database/migrations/*_'.self::MIGRATION.'.php')) ?: [];

        if ($published === []) {
            return StepResult::failed(['The migration was not published to database/migrations']);
        }

        $relative = $context->relative($published[0]);

        if ($context->isProduction() && ! $context->force()) {
            return StepResult::manual(["Published {$relative}. This is production, so run it yourself: php artisan migrate --force"], $notes);
        }

        if (! $context->confirm("Run {$relative}?")) {
            $context->next[] = 'php artisan migrate';

            return StepResult::skipped("published {$relative}, not run yet", $notes);
        }

        return $this->migrate($context, ['--path' => $published[0], '--realpath' => true], [$relative], $notes);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  list<string>  $details
     * @param  list<string>  $notes
     */
    protected function migrate(InstallContext $context, array $arguments, array $details, array $notes): StepResult
    {
        try {
            $code = $context->call('migrate', $arguments + ['--force' => true]);
        } catch (Throwable $e) {
            return StepResult::failed(['Migration failed: '.$e->getMessage()], warnings: $notes);
        }

        if ($code !== 0) {
            return StepResult::failed(['Migration failed. Run php artisan migrate to see why.'], warnings: $notes);
        }

        return StepResult::done($details, $notes);
    }

    /**
     * Migrations that `migrate` would run now, name => path.
     *
     * @return array<string, string>
     */
    public function pending(InstallContext $context): array
    {
        /** @var Migrator $migrator */
        $migrator = $context->command->getLaravel()->make('migrator');

        $files = $migrator->getMigrationFiles(array_merge(
            $migrator->paths(),
            [$context->path('database/migrations')],
        ));

        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        $pending = array_diff_key($files, array_flip($ran));
        ksort($pending);

        return $pending;
    }

    protected function hasLegacyMigrations(InstallContext $context): bool
    {
        return (glob($context->path('database/migrations/*_create_digital_signatures_table.php')) ?: []) !== [];
    }
}
