<?php

/**
 * MySQL and Postgres require a foreign key's target table to already exist at
 * CREATE TABLE time; SQLite does not. So the package test suite, which runs on
 * SQLite, will happily accept a migration order that fails on the databases
 * most hosts actually use.
 *
 * These tests read the migration files and check the ordering directly.
 */
function migrationFiles(): array
{
    $files = glob(__DIR__.'/../../database/migrations/*.php') ?: [];
    sort($files);

    return $files;
}

describe('migration ordering', function () {

    it('creates every foreign key target before the table that references it', function () {
        // Tables the host application already has when the package's
        // migrations run — Laravel's own `users` table is created by the
        // framework's default migrations.
        $created = ['users'];
        $problems = [];

        foreach (migrationFiles() as $file) {
            $source = file_get_contents($file);
            $name = basename($file);

            preg_match('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $source, $self);
            $selfTable = $self[1] ?? null;

            // ->constrained('target') and the implicit ->constrained() form,
            // which infers the table from the column name.
            preg_match_all('/->constrained\(\s*[\'"]([^\'"]+)[\'"]/', $source, $explicit);

            foreach ($explicit[1] as $target) {
                // A self-reference is fine — the table exists by then.
                if ($target === $selfTable) {
                    continue;
                }

                if (! in_array($target, $created, true)) {
                    $problems[] = "{$name} references [{$target}] before it is created";
                }
            }

            if ($selfTable !== null) {
                $created[] = $selfTable;
            }
        }

        expect($problems)->toBe([]);
    });

    it('has no gaps or duplicates in its ordering prefixes', function () {
        $prefixes = array_map(
            fn ($f) => (int) substr(basename($f), 11, 6),
            migrationFiles(),
        );

        expect($prefixes)->toBe(range(1, count($prefixes)));
    });

    it('has no alter-table migrations — schema is declared where the table is created', function () {
        $alters = [];

        foreach (migrationFiles() as $file) {
            if (str_contains(file_get_contents($file), 'Schema::table(')) {
                $alters[] = basename($file);
            }
        }

        // Every column this package needs is declared in the create migration
        // for its table. An `add_x_to_y` migration would only be warranted for
        // schema that had already shipped to hosts.
        expect($alters)->toBe([]);
    });

    it('runs against a database that actually enforces foreign keys', function () {
        // SQLite ignores foreign keys unless asked, so without this the suite
        // would pass on schema that a host app's MySQL would reject.
        expect(\Illuminate\Support\Facades\DB::select('PRAGMA foreign_keys')[0]->foreign_keys)->toBe(1);

        expect(fn () => \Illuminate\Support\Facades\DB::table('digital_signatures')->insert([
            'user_id'    => 99999,
            'image_path' => 'x.png',
            'image_hash' => str_repeat('a', 64),
        ]))->toThrow(\Illuminate\Database\QueryException::class);
    });

    it('drops in its own table in every down()', function () {
        foreach (migrationFiles() as $file) {
            $source = file_get_contents($file);

            if (! preg_match('/Schema::create\(\s*[\'"]([^\'"]+)[\'"]/', $source, $m)) {
                continue;   // data-only migration
            }

            expect($source)->toContain("Schema::dropIfExists('{$m[1]}')");
        }
    });
});
