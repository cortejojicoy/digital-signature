<?php

namespace Kukux\DigitalSignature\Console\Install\Steps;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Schema;
use Kukux\DigitalSignature\Console\Install\InstallContext;
use Kukux\DigitalSignature\Console\Install\InstallStep;
use Kukux\DigitalSignature\Console\Install\StepResult;
use Throwable;

class RunMigration implements InstallStep
{
    /** The package migration, without its timestamp. */
    public const MIGRATION = 'create_digital_signature_tables';

    /** Every table the package migration creates. */
    public const TABLES = [
        'digital_user_certificates', 'digital_signature_devices', 'digital_signing_sessions',
        'digital_signatures', 'signature_positions', 'digital_signature_requests',
        'digital_signature_delegations', 'digital_signature_audits', 'digital_pdf_template_slots',
        'digital_signature_agent_pairings', 'digital_signature_agent_tokens', 'digital_signature_agent_jobs',
    ];

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
            $missing = $this->missingTables();

            return $missing === []
                ? StepResult::skipped('nothing to migrate', $notes)
                : $this->repair($context, $missing, $notes);
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
     * The migrations table says the package migration ran, but its tables
     * aren't there: they were dropped by hand, or the database was restored
     * without them. The migration only creates what's missing, so forgetting
     * that it ran and running it again is safe.
     *
     * @param  list<string>  $missing
     * @param  list<string>  $notes
     */
    protected function repair(InstallContext $context, array $missing, array $notes): StepResult
    {
        $problem = [
            'The migrations table says the package migration ran, but these tables are missing:',
            '    '.implode(', ', $missing),
        ];

        $leftover = array_values(array_diff(self::TABLES, $missing));

        if ($leftover !== []) {
            // Recreated tables get their foreign keys; ones left behind may
            // have lost theirs when their targets were dropped.
            $notes[] = 'These tables were left in place: '.implode(', ', $leftover).'. If they\'re empty, drop them first so they\'re recreated with their foreign keys.';
        }

        $file = $this->packageMigrationFile($context);

        if ($file === null) {
            return StepResult::manual(array_merge($problem, ['Couldn\'t find the package migration file to run again.']), $notes);
        }

        $name = basename($file, '.php');

        if ($context->dryRun()) {
            return StepResult::dryRun(array_merge($problem, ["would forget {$name} ran and run it again"]));
        }

        if ($context->isProduction() && ! $context->force()) {
            return StepResult::manual(array_merge($problem, ['This is production, so nothing was changed. To repair it, rerun with --force.']), $notes);
        }

        if (! $context->confirm('Run the package migration again to create them?')) {
            return StepResult::manual(array_merge($problem, ['Not repaired. Rerun signature:install when you\'re ready.']), $notes);
        }

        /** @var Migrator $migrator */
        $migrator = $context->command->getLaravel()->make('migrator');
        $migrator->getRepository()->delete((object) ['migration' => $name]);

        $result = $this->migrate($context, ['--path' => $file, '--realpath' => true], array_merge($problem, ["re-ran {$name}"]), $notes);

        if ($result->status === StepResult::DONE && ($still = $this->missingTables()) !== []) {
            return StepResult::failed(['Re-ran the migration, but these tables are still missing: '.implode(', ', $still)], warnings: $notes);
        }

        return $result;
    }

    /** @return list<string> */
    protected function missingTables(): array
    {
        return array_values(array_filter(self::TABLES, fn (string $table) => ! Schema::hasTable($table)));
    }

    /** The package migration this app runs: its published copy, or the one in vendor/. */
    protected function packageMigrationFile(InstallContext $context): ?string
    {
        $published = glob($context->path('database/migrations/*_'.self::MIGRATION.'.php')) ?: [];

        if ($published !== []) {
            return $published[0];
        }

        $vendor = glob(dirname(__DIR__, 4).'/database/migrations/*_'.self::MIGRATION.'.php') ?: [];

        return $vendor[0] ?? null;
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
