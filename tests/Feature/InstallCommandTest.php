<?php

use Illuminate\Console\Events\CommandFinished;
use Illuminate\Support\Facades\Schema;
use Kukux\DigitalSignature\Console\Install\EnvFile;
use Kukux\DigitalSignature\Console\Install\InstallContext;
use Kukux\DigitalSignature\Console\Install\PhpFileEditor;
use Kukux\DigitalSignature\Console\Install\Steps\PublishConfig;
use Kukux\DigitalSignature\Console\Install\Steps\RegisterPlugin;
use Kukux\DigitalSignature\Console\Install\Steps\RegisterPolicy;
use Kukux\DigitalSignature\Console\InstallCommand;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * signature:install, run against a throwaway app skeleton so nothing outside
 * the temp directory is ever written.
 */
const PANEL_PROVIDER = <<<'PHP'
<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Panel;
use Filament\PanelProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
PHP;

const APP_SERVICE_PROVIDER = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        //
    }
}
PHP;

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/dsig-install-*') ?: [] as $dir) {
        \Illuminate\Support\Facades\File::deleteDirectory($dir);
    }
});

function installApp(): string
{
    $base = sys_get_temp_dir().'/dsig-install-'.bin2hex(random_bytes(4));

    foreach (['app/Providers/Filament', 'config', 'database/migrations', 'public'] as $dir) {
        mkdir("{$base}/{$dir}", 0755, true);
    }

    file_put_contents("{$base}/app/Providers/Filament/AdminPanelProvider.php", PANEL_PROVIDER);
    file_put_contents("{$base}/app/Providers/AppServiceProvider.php", APP_SERVICE_PROVIDER);
    file_put_contents("{$base}/.env", "APP_NAME=Laravel\nAPP_ENV=local\n");
    file_put_contents("{$base}/.env.example", "APP_NAME=Laravel\n");

    app()->setBasePath($base);

    return $base;
}

/** @return array<string, string> relative path => sha1, for every file under $base */
function snapshot(string $base): array
{
    $files = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));

    foreach ($it as $file) {
        $files[substr($file->getPathname(), strlen($base) + 1)] = sha1_file($file->getPathname());
    }

    ksort($files);

    return $files;
}

function lintsOk(string $path): bool
{
    return PhpFileEditor::lints($path);
}

function contextFor(string $base, array $options = []): InstallContext
{
    $command = app(InstallCommand::class);
    $command->setLaravel(app());
    $command->setOutput(new \Illuminate\Console\OutputStyle(new ArrayInput([]), new BufferedOutput));

    return new InstallContext($command, $base, $options);
}

describe('signature:install', function () {

    it('installs everything on a fresh app, asking before each code edit', function () {
        $base = installApp();

        $this->artisan('signature:install', ['--no-assets' => true])
            ->expectsConfirmation('Continue?', 'yes')
            ->expectsConfirmation('Apply this edit to app/Providers/Filament/AdminPanelProvider.php?', 'yes')
            ->expectsConfirmation('Apply this edit to app/Providers/AppServiceProvider.php?', 'yes')
            ->expectsOutputToContain('Digital Signature is installed.')
            ->assertSuccessful();

        expect(file_exists("{$base}/config/signature.php"))->toBeTrue();

        $env = new EnvFile("{$base}/.env");
        expect($env->get('SIGNATURE_DISK'))->toBe('local')
            ->and($env->get('SIGNATURE_AUTO_AFFIX_MODE'))->toBe('approval')
            ->and((new EnvFile("{$base}/.env.example"))->has('SIGNATURE_DISK'))->toBeTrue();

        $panel = file_get_contents("{$base}/app/Providers/Filament/AdminPanelProvider.php");
        expect($panel)->toContain(RegisterPlugin::IMPORT)
            ->and($panel)->toContain("->plugins([\n                SignaturePlugin::make(),\n            ])")
            ->and(lintsOk("{$base}/app/Providers/Filament/AdminPanelProvider.php"))->toBeTrue();

        $provider = file_get_contents("{$base}/app/Providers/AppServiceProvider.php");
        expect($provider)->toContain('Gate::policy(\Kukux\DigitalSignature\Models\Signature::class, \App\Policies\DigitalSignaturePolicy::class);')
            ->and(lintsOk("{$base}/app/Providers/AppServiceProvider.php"))->toBeTrue()
            ->and(lintsOk("{$base}/app/Policies/DigitalSignaturePolicy.php"))->toBeTrue()
            ->and(file_get_contents("{$base}/app/Policies/DigitalSignaturePolicy.php"))->toContain('namespace App\Policies;');
    });

    it('changes nothing when run a second time', function () {
        $base = installApp();

        $this->artisan('signature:install', ['--no-assets' => true, '--force' => true, '--no-interaction' => true])->assertSuccessful();
        $before = snapshot($base);

        $this->artisan('signature:install', ['--no-assets' => true, '--no-interaction' => true])
            ->expectsOutputToContain('SKIPPED')
            ->assertSuccessful();

        expect(snapshot($base))->toBe($before);
    });

    it('keeps a value already in .env', function () {
        $base = installApp();
        file_put_contents("{$base}/.env", "SIGNATURE_PDF_DRIVER=tcpdf\n");

        $this->artisan('signature:install', ['--no-assets' => true, '--no-panel' => true, '--no-policy' => true, '--no-interaction' => true])
            ->expectsOutputToContain('kept 1 you already had')
            ->assertSuccessful();

        $env = file_get_contents("{$base}/.env");
        expect(substr_count($env, 'SIGNATURE_PDF_DRIVER='))->toBe(1)
            ->and($env)->toContain('SIGNATURE_PDF_DRIVER=tcpdf');
    });

    it('warns about a trimmed config block and leaves the file alone', function () {
        $base = installApp();
        $config = "<?php\n\nreturn ['launcher' => ['position' => 'bottom-left']];\n";
        file_put_contents("{$base}/config/signature.php", $config);

        $this->artisan('signature:install', ['--no-assets' => true, '--no-panel' => true, '--no-policy' => true, '--no-interaction' => true])
            ->expectsOutputToContain('launcher.enabled')
            ->assertSuccessful();

        expect(file_get_contents("{$base}/config/signature.php"))->toBe($config);
    });

    it('stops before migrating when the signature disk is public', function () {
        $base = installApp();
        file_put_contents("{$base}/.env", "SIGNATURE_DISK=public\n");
        config()->set('filesystems.disks.public', ['driver' => 'local', 'root' => "{$base}/storage/app/public", 'visibility' => 'public']);

        $this->artisan('signature:install', ['--no-assets' => true, '--no-interaction' => true])
            ->expectsOutputToContain('publicly readable')
            ->doesntExpectOutputToContain('Running migration')
            ->assertFailed();
    });

    it('does not migrate in production without --force', function () {
        $base = installApp();
        file_put_contents("{$base}/database/migrations/2026_01_01_000000_create_install_probe_table.php", <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('install_probe', fn ($t) => $t->id());
            }
        };
        PHP);
        app()['env'] = 'production';

        try {
            $this->artisan('signature:install', ['--no-assets' => true, '--no-panel' => true, '--no-policy' => true, '--no-interaction' => true])
                ->expectsOutputToContain('MANUAL')
                ->expectsOutputToContain('php artisan migrate --force')
                ->assertSuccessful();
        } finally {
            // Testbench's own teardown asks for confirmation in production.
            app()['env'] = 'testing';
        }

        expect(Schema::hasTable('install_probe'))->toBeFalse();
    });

    it('runs pending migrations when told to', function () {
        $base = installApp();
        file_put_contents("{$base}/database/migrations/2026_01_01_000000_create_install_probe_table.php", <<<'PHP'
        <?php

        use Illuminate\Database\Migrations\Migration;
        use Illuminate\Support\Facades\Schema;

        return new class extends Migration
        {
            public function up(): void
            {
                Schema::create('install_probe', fn ($t) => $t->id());
            }
        };
        PHP);

        $this->artisan('signature:install', ['--no-assets' => true, '--no-panel' => true, '--no-policy' => true, '--no-interaction' => true])
            ->expectsOutputToContain('2026_01_01_000000_create_install_probe_table')
            ->expectsOutputToContain('pending migration(s) from your app')
            ->assertSuccessful();

        expect(Schema::hasTable('install_probe'))->toBeTrue();
    });

    it('changes nothing on a dry run', function () {
        $base = installApp();
        $before = snapshot($base);

        $this->artisan('signature:install', ['--dry-run' => true, '--no-assets' => true])
            ->expectsOutputToContain('DRY RUN')
            ->expectsOutputToContain('Dry run finished')
            ->assertSuccessful();

        expect(snapshot($base))->toBe($before);
    });

    it('leaves code edits to the user under -n without --force', function () {
        $base = installApp();
        $panel = file_get_contents("{$base}/app/Providers/Filament/AdminPanelProvider.php");

        $this->artisan('signature:install', ['--no-assets' => true, '--no-interaction' => true])
            ->expectsOutputToContain('MANUAL')
            ->expectsOutputToContain('SignaturePlugin::make(),')
            ->assertSuccessful();

        expect(file_get_contents("{$base}/app/Providers/Filament/AdminPanelProvider.php"))->toBe($panel)
            ->and(file_get_contents("{$base}/app/Providers/AppServiceProvider.php"))->toBe(APP_SERVICE_PROVIDER);
    });

    it('pins the agent server id and salt with --agent', function () {
        $base = installApp();

        $this->artisan('signature:install', ['--agent' => true, '--no-assets' => true, '--no-panel' => true, '--no-policy' => true, '--no-interaction' => true])
            ->assertSuccessful();

        $env = new EnvFile("{$base}/.env");
        expect($env->get('SIGNATURE_AGENT_ENABLED'))->toBe('true')
            ->and($env->get('SIGNATURE_AGENT_SERVER_ID'))->toBe(\Kukux\DigitalSignature\Agent\AgentServer::id())
            ->and($env->get('SIGNATURE_AGENT_SALT'))->toBe(\Kukux\DigitalSignature\Agent\AgentServer::salt())
            // .env.example gets the keys, never the values.
            ->and((new EnvFile("{$base}/.env.example"))->get('SIGNATURE_AGENT_SALT'))->toBe('');
    });
});

describe('editing the user\'s code', function () {

    it('adds the plugin to an existing plugins list', function () {
        $source = "<?php\n\nnamespace App;\n\nuse Filament\\Panel;\n\nclass P\n{\n    public function panel(Panel \$panel): Panel\n    {\n        return \$panel\n            ->plugins([\n                Other::make(),\n            ]);\n    }\n}\n";

        $updated = (new RegisterPlugin)->edit($source);

        expect($updated)->toContain("->plugins([\n                SignaturePlugin::make(),\n                Other::make(),")
            ->and($updated)->toContain("use Filament\\Panel;\n".RegisterPlugin::IMPORT);
    });

    it('fills an empty plugins list', function () {
        $source = "<?php\n\nnamespace App;\n\nclass P\n{\n    public function panel(\$panel)\n    {\n        return \$panel\n            ->plugins([]);\n    }\n}\n";

        expect((new RegisterPlugin)->edit($source))->toContain("->plugins([\n                SignaturePlugin::make(),\n            ]);");
    });

    it('refuses a shape it cannot edit with confidence', function () {
        $source = "<?php\n\nclass P\n{\n    public function panel(\$panel)\n    {\n        \$panel->id('a');\n\n        return \$panel;\n    }\n}\n";

        expect((new RegisterPlugin)->edit($source))->toBeNull();
    });

    it('puts the policy first in boot()', function () {
        $updated = (new RegisterPolicy)->edit(APP_SERVICE_PROVIDER, 'Gate::policy(A::class, B::class);');

        expect($updated)->toContain("    public function boot(): void\n    {\n        Gate::policy(A::class, B::class);\n        //\n    }");
    });

    it('restores the original when an edit does not lint', function () {
        $base = installApp();
        $path = "{$base}/app/Providers/AppServiceProvider.php";

        $result = (new PhpFileEditor(contextFor($base, ['force' => true])))->write($path, "<?php\n\nclass Broken {\n");

        expect($result)->toBe(PhpFileEditor::LINT_FAILED)
            ->and(file_get_contents($path))->toBe(APP_SERVICE_PROVIDER);
    });

    it('finds keys missing from a kept block, but not from a dropped one', function () {
        $missing = (new PublishConfig)->missingKeys(
            ['launcher' => ['enabled' => true, 'width' => '64rem'], 'devices' => ['agent' => ['salt' => null]], 'qr' => ['enabled' => true]],
            ['launcher' => ['enabled' => true], 'devices' => ['agent' => []]],
        );

        expect($missing)->toBe(['launcher.width', 'devices.agent.salt']);
    });
});

describe('the hint after package:discover', function () {

    function discover(): string
    {
        $output = new BufferedOutput;
        event(new CommandFinished('package:discover', new ArrayInput([]), $output, 0));

        return $output->fetch();
    }

    it('points at signature:install until the package is set up', function () {
        installApp();
        Schema::disableForeignKeyConstraints();
        Schema::drop('digital_signatures');

        expect(discover())->toContain('php artisan signature:install');
    });

    it('stays quiet once the package is set up', function () {
        installApp();

        // The suite has already migrated the package tables.
        expect(discover())->toBe('');
    });

    it('stays quiet in production', function () {
        installApp();
        Schema::disableForeignKeyConstraints();
        Schema::drop('digital_signatures');
        app()['env'] = 'production';

        try {
            expect(discover())->toBe('');
        } finally {
            app()['env'] = 'testing';
        }
    });
});
