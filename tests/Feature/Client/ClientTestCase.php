<?php

namespace Kukux\DigitalSignature\Tests\Feature\Client;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Contracts\DeferredPdfSigner;
use Kukux\DigitalSignature\Models\HubAccount;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Tests\Support\TestUser;

/**
 * The package booted in client mode against a fake hub at https://hub.test.
 * In each test file:
 *
 *   uses(ClientTestCase::class);
 *   beforeEach(fn () => $this->setUpClient());
 *
 * A trait, not a TestCase subclass: tests/Pest.php already gives every file
 * under Feature/ the package TestCase, and Pest allows one class per file.
 * Testbench registers package providers before getEnvironmentSetUp() runs,
 * and SignatureServiceProvider picks ClientServiceProvider in register(), so
 * the mode is set right after configuration loads. The hub keys go in
 * getEnvironmentSetUp(), which still runs before providers boot (where the
 * client checks them).
 */
trait ClientTestCase
{
    public FakeDeferredPdfSigner $deferred;

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('signature.mode', 'client');
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        foreach ([
            'url'            => 'https://hub.test',
            'client_id'      => 'performance',
            'client_secret'  => 'client-secret',
            'webhook_secret' => 'whsec-test',
            'mirror_disk'    => null,
        ] as $key => $value) {
            $app['config']->set("signature.hub.{$key}", $value);
        }

        $app['config']->set('cache.default', 'array');
    }

    protected function setUpClient(): void
    {
        Storage::fake('testing');
        Cache::flush();

        $this->deferred = new FakeDeferredPdfSigner;
        $this->app->instance(DeferredPdfSigner::class, $this->deferred);
    }

    // ── The fake hub ──────────────────────────────────────────────────────

    /** The token endpoint, plus whatever else the test answers. */
    public function fakeHub(array $routes = []): void
    {
        Http::fake($routes + [
            'hub.test/signature/hub/oauth/token' => Http::response([
                'access_token' => 'app-token',
                'token_type'   => 'Bearer',
                'expires_in'   => 3600,
                'scope'        => 'signatures.read sign',
            ]),
        ]);
    }

    /** Signature metadata + image routes for one person. */
    public function hubSignatureRoutes(string $sub, string $bytes, string $uuid = 'aaaaaaaa-1111-4111-8111-111111111111', ?string $sha = null): array
    {
        $sha ??= hash('sha256', $bytes);

        return [
            "hub.test/signature/hub/api/v1/people/{$sub}/signature/image" => fn ($request) => trim((string) ($request->header('If-None-Match')[0] ?? ''), '"') === $sha
                ? Http::response('', 304)
                : Http::response($bytes, 200, ['ETag' => "\"{$sha}\"", 'X-Image-Sha256' => $sha, 'Content-Type' => 'image/png']),
            "hub.test/signature/hub/api/v1/people/{$sub}/signature" => Http::response([
                'uuid'                    => $uuid,
                'status'                  => 'active',
                'image_sha256'            => $sha,
                'certificate_fingerprint' => str_repeat('c', 64),
                'updated_at'              => now()->toIso8601String(),
            ]),
        ];
    }

    // ── People here ───────────────────────────────────────────────────────

    public function linkedUser(int $id = 11, string $sub = 'p-juan', string $name = 'Juan Dela Cruz'): TestUser
    {
        $user = TestUser::query()->find($id) ?? TestUser::create([
            'id'    => $id,
            'name'  => $name,
            'email' => Str::slug($name).'@up.edu.ph',
        ]);

        HubAccount::create(['user_id' => $user->id, 'sub' => $sub, 'claims' => ['sub' => $sub], 'linked_at' => now()]);

        return $user;
    }

    /** A mirror row whose image is really on the disk. */
    public function mirror(int $userId, ?string $bytes = null, string $hubUuid = 'aaaaaaaa-1111-4111-8111-111111111111'): Signature
    {
        $bytes ??= stampablePng();
        $path = "signatures/hub/{$hubUuid}.png";

        Storage::disk('testing')->put($path, $bytes);

        return Signature::create([
            'uuid'           => (string) Str::uuid(),
            'user_id'        => $userId,
            'image_path'     => $path,
            'image_hash'     => hash('sha256', $bytes),
            'source'         => 'hub',
            'status'         => 'active',
            'hub_uuid'       => $hubUuid,
            'hub_image_hash' => hash('sha256', $bytes),
            'hub_synced_at'  => now(),
        ]);
    }

    /** A webhook as the hub sends it. */
    public function webhook(string $event, array $data, ?string $id = null, ?int $timestamp = null, ?string $secret = null)
    {
        $id ??= (string) Str::uuid();
        $timestamp ??= now()->getTimestamp();
        $body = json_encode(['id' => $id, 'event' => $event, 'created_at' => now()->toIso8601String(), 'data' => $data]);
        $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, $secret ?? 'whsec-test');

        return $this->call('POST', '/signature/hub/webhook', [], [], [], [
            'CONTENT_TYPE'                   => 'application/json',
            'HTTP_ACCEPT'                    => 'application/json',
            'HTTP_X_SIGNATURE_HUB_ID'        => $id,
            'HTTP_X_SIGNATURE_HUB_EVENT'     => $event,
            'HTTP_X_SIGNATURE_HUB_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SIGNATURE_HUB_SIGNATURE' => $signature,
        ], $body);
    }
}
