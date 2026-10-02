<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * MySQL and Postgres require a foreign key's target table to already exist at
 * CREATE TABLE time; SQLite does not. So the package test suite, which runs on
 * SQLite, will happily accept a migration order that fails on the databases
 * most hosts actually use.
 *
 * These tests read the migration source and check the ordering directly, then
 * run it against the database states a host can be in.
 */
function migrationFiles(): array
{
    $files = glob(__DIR__.'/../../database/migrations/*.php') ?: [];
    sort($files);

    return $files;
}

function packageMigration(): Migration
{
    return require migrationFiles()[0];
}

/**
 * Every Schema::create in the migration, in order, with the source of its
 * blueprint closure.
 *
 * @return array<int, array{table: string, body: string}>
 */
function createdTables(): array
{
    $source = file_get_contents(migrationFiles()[0]);
    $parts = preg_split('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $source, -1, PREG_SPLIT_DELIM_CAPTURE);

    $tables = [];

    for ($i = 1; $i < count($parts); $i += 2) {
        $tables[] = ['table' => $parts[$i], 'body' => $parts[$i + 1]];
    }

    return $tables;
}

/** Tables the package owns, as the migration declares them. */
function packageTables(): array
{
    return array_column(createdTables(), 'table');
}

describe('migration ordering', function () {

    it('ships as one migration, so a host has a single file to run or publish', function () {
        expect(migrationFiles())->toHaveCount(1);
    });

    it('creates every foreign key target before the table that references it', function () {
        // Laravel's own `users` table is created by the host's migrations.
        $created = ['users'];
        $problems = [];

        foreach (createdTables() as ['table' => $table, 'body' => $body]) {
            preg_match_all('/->constrained\(\s*[\'"]([^\'"]+)[\'"]/', $body, $explicit);

            foreach ($explicit[1] as $target) {
                // A self-reference is fine — the table exists by then.
                if ($target !== $table && ! in_array($target, $created, true)) {
                    $problems[] = "[{$table}] references [{$target}] before it is created";
                }
            }

            $created[] = $table;
        }

        expect($problems)->toBe([]);
    });

    it('guards every create and every late column, so it is safe on an existing install', function () {
        $source = file_get_contents(migrationFiles()[0]);

        foreach (packageTables() as $table) {
            expect($source)->toContain("if (! Schema::hasTable('{$table}'))");
        }

        preg_match_all('/Schema::table\(\s*[\'"]([^\'"]+)[\'"]/', $source, $alters);

        foreach ($alters[1] as $table) {
            expect($source)->toMatch("/Schema::hasColumn\\('{$table}'/");
        }
    });

    it('drops every table it creates in down()', function () {
        $source = file_get_contents(migrationFiles()[0]);

        foreach (packageTables() as $table) {
            expect($source)->toContain("Schema::dropIfExists('{$table}')");
        }
    });

    it('runs against a database that actually enforces foreign keys', function () {
        // SQLite ignores foreign keys unless asked, so without this the suite
        // would pass on schema that a host app's MySQL would reject.
        expect(DB::select('PRAGMA foreign_keys')[0]->foreign_keys)->toBe(1);

        expect(fn () => DB::table('digital_signatures')->insert([
            'user_id'    => 99999,
            'image_path' => 'x.png',
            'image_hash' => str_repeat('a', 64),
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });
});

describe('running on an existing install', function () {

    it('is a no-op when run a second time', function () {
        packageMigration()->up();

        foreach (packageTables() as $table) {
            expect(Schema::hasTable($table))->toBeTrue();
        }
    });

    it('adds the columns an older release never got, and keeps the data', function () {
        makeFakeUser();
        $signature = makePrimarySignature(1);

        // An install from before caption_position and device existed.
        Schema::table('signature_positions', fn ($t) => $t->dropColumn('caption_position'));
        Schema::table('digital_signature_audits', fn ($t) => $t->dropConstrainedForeignId('device_id'));
        Schema::table('digital_signatures', fn ($t) => $t->dropConstrainedForeignId('device_id'));
        Schema::drop('digital_signature_agent_jobs');

        packageMigration()->up();

        expect(Schema::hasColumn('signature_positions', 'caption_position'))->toBeTrue()
            ->and(Schema::hasColumn('digital_signatures', 'device_id'))->toBeTrue()
            ->and(Schema::hasColumn('digital_signature_audits', 'device_id'))->toBeTrue()
            ->and(Schema::hasTable('digital_signature_agent_jobs'))->toBeTrue()
            ->and(DB::table('digital_signatures')->where('id', $signature->id)->exists())->toBeTrue();
    });

    it('adds the one-signature-per-computer columns, keying only the newest duplicate', function () {
        makeFakeUser();
        DB::table('users')->insert(['id' => 2, 'name' => 'Maria', 'email' => 'maria@example.test', 'password' => 'x']);

        // An install from before the rule: no new columns, and two accounts
        // with an active agent on the same computer.
        Schema::table('digital_signature_devices', fn ($t) => $t->dropUnique('dsd_active_hardware_unique'));
        Schema::table('digital_signature_devices', fn ($t) => $t->dropColumn([
            'active_hardware_key', 'detected_device_type', 'chassis_type', 'virtual', 'rebound_at',
        ]));
        Schema::table('digital_signature_agent_pairings', fn ($t) => $t->dropConstrainedForeignId('replaces_device_id'));

        $device = fn (int $userId, string $fingerprint) => DB::table('digital_signature_devices')->insertGetId([
            'uuid' => (string) \Illuminate\Support\Str::uuid(), 'user_id' => $userId, 'label' => 'Mac',
            'public_key' => 'pem', 'key_fingerprint' => $fingerprint, 'algorithm' => 'ES256',
            'kind' => 'agent', 'status' => 'active', 'hardware_id_hash' => str_repeat('ab', 32),
        ]);
        $older = $device(1, str_repeat('1', 64));
        $newer = $device(2, str_repeat('2', 64));

        packageMigration()->up();

        expect(Schema::hasColumns('digital_signature_devices', ['active_hardware_key', 'detected_device_type', 'chassis_type', 'virtual', 'rebound_at']))->toBeTrue()
            ->and(Schema::hasColumn('digital_signature_agent_pairings', 'replaces_device_id'))->toBeTrue()
            // Both stay active: nobody loses a working device on upgrade.
            ->and(DB::table('digital_signature_devices')->where('status', 'active')->count())->toBe(2)
            ->and(DB::table('digital_signature_devices')->where('id', $newer)->value('active_hardware_key'))->toBe(str_repeat('ab', 32))
            ->and(DB::table('digital_signature_devices')->where('id', $older)->value('active_hardware_key'))->toBeNull();
    });

    it('does not drop tables on rollback that the pre-consolidation migrations created', function () {
        // A published copy of the old create migration, as a host records it.
        app('migration.repository')->log('2026_03_31_101500_create_digital_signatures_table', 1);

        packageMigration()->down();

        foreach (packageTables() as $table) {
            expect(Schema::hasTable($table))->toBeTrue();
        }
    });

    it('drops its tables on rollback of a fresh install', function () {
        packageMigration()->down();

        foreach (packageTables() as $table) {
            expect(Schema::hasTable($table))->toBeFalse();
        }
    });
});
