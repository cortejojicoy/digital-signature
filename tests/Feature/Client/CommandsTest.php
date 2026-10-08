<?php

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Kukux\DigitalSignature\Console\Install\EnvFile;
use Kukux\DigitalSignature\Models\HubAccount;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Tests\Feature\Client\ClientTestCase;

/**
 * signature:install --mode=client, signature:hub-sync (and its dry run).
 * signature:hub-retry is covered with the signing flow.
 */
uses(ClientTestCase::class);

beforeEach(fn () => $this->setUpClient());

afterEach(function () {
    foreach (glob(sys_get_temp_dir().'/dsig-client-install-*') ?: [] as $dir) {
        File::deleteDirectory($dir);
    }
});

describe('signature:install --mode=client', function () {

    it('writes SIGNATURE_MODE=client and blank hub keys, without certificate or agent settings', function () {
        $base = sys_get_temp_dir().'/dsig-client-install-'.bin2hex(random_bytes(4));
        foreach (['app/Providers', 'config', 'database/migrations', 'public'] as $dir) {
            mkdir("{$base}/{$dir}", 0755, true);
        }
        file_put_contents("{$base}/.env", "APP_NAME=Perf\nAPP_URL=https://performance.uplb.test\n");
        file_put_contents("{$base}/.env.example", "APP_NAME=Laravel\n");
        app()->setBasePath($base);

        $this->artisan('signature:install', [
            '--mode' => 'client', '--agent' => true, '--no-assets' => true, '--no-panel' => true,
            '--no-policy' => true, '--no-migrate' => true, '--no-interaction' => true,
        ])
            ->expectsOutputToContain('--agent is ignored')
            ->expectsOutputToContain('Register this app as a client in the hub admin panel (redirect URI https://performance.uplb.test/signature/hub/callback, webhook URL https://performance.uplb.test/signature/hub/webhook)')
            ->assertSuccessful();

        $env = new EnvFile("{$base}/.env");

        expect($env->get('SIGNATURE_MODE'))->toBe('client')
            ->and($env->get('SIGNATURE_HUB_URL'))->toBe('')
            ->and($env->has('SIGNATURE_HUB_CLIENT_SECRET'))->toBeTrue()
            ->and($env->has('SIGNATURE_HUB_WEBHOOK_SECRET'))->toBeTrue()
            ->and($env->has('SIGNATURE_CERT_DRIVER'))->toBeFalse()
            ->and($env->has('SIGNATURE_AGENT_ENABLED'))->toBeFalse()
            ->and($env->get('SIGNATURE_DISK'))->toBe('local')
            ->and((new EnvFile("{$base}/.env.example"))->has('SIGNATURE_MODE'))->toBeTrue();
    });

    it('refuses an unknown mode', function () {
        $this->artisan('signature:install', ['--mode' => 'hub', '--no-interaction' => true])->assertFailed();
    });
});

describe('signature:hub-sync', function () {

    it('pulls a mirror for every linked user', function () {
        $user = $this->linkedUser();
        $png = stampablePng();
        $this->fakeHub($this->hubSignatureRoutes('p-juan', $png));

        $this->artisan('signature:hub-sync')->expectsOutputToContain('1 mirrors up to date')->assertSuccessful();

        expect(Signature::query()->where('user_id', $user->id)->where('source', 'hub')->value('hub_image_hash'))->toBe(hash('sha256', $png));
    });

    it('skips mirrors checked since --since', function () {
        $this->mirror($this->linkedUser()->id);
        Http::fake();

        $this->artisan('signature:hub-sync', ['--since' => now()->subHour()->toIso8601String()])
            ->expectsOutputToContain('1 skipped')
            ->assertSuccessful();

        Http::assertNothingSent();
    });

    it('reports matches on a dry run and changes nothing', function () {
        $this->linkedUser(11, 'p-juan');
        $maria = makeUser(12, 'Maria Santos', 'maria@up.edu.ph');
        makePrimarySignature($maria->id);
        makeUser(13, 'Nobody Here', 'nobody@up.edu.ph');

        $this->fakeHub([
            'hub.test/signature/hub/api/v1/people/p-maria/signature' => Http::response(['error' => 'no_signature', 'message' => 'None.'], 404),
            'hub.test/signature/hub/api/v1/people*' => fn ($request) => Http::response(['data' => str_contains($request->url(), 'maria') ? [['sub' => 'p-maria']] : []]),
        ]);

        $this->artisan('signature:hub-sync', ['--dry-run' => true])
            ->expectsOutputToContain('1 already linked, 0 match by emp_no, 1 by email, 1 unmatched; 1 have a signature here but none at the hub')
            ->assertSuccessful();

        expect(HubAccount::query()->count())->toBe(1)
            ->and(Signature::query()->where('source', 'hub')->count())->toBe(0);
        Http::assertNotSent(fn ($r) => $r->method() === 'POST' && ! str_ends_with($r->url(), '/oauth/token'));
    });
});
