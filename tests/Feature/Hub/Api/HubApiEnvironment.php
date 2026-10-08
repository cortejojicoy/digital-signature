<?php

namespace Kukux\DigitalSignature\Tests\Feature\Hub\Api;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Kukux\DigitalSignature\Agent\AgentJobService;
use Kukux\DigitalSignature\Contracts\DigestSigner;
use Kukux\DigitalSignature\Contracts\PersonnelDirectory;
use Kukux\DigitalSignature\Hub\OAuth\HubAppRegistrar;
use Kukux\DigitalSignature\Hub\OAuth\HubOAuthServer;
use Kukux\DigitalSignature\Hub\Personnel;
use Kukux\DigitalSignature\Models\AgentJob;
use Kukux\DigitalSignature\Models\HubApp;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Security\DeviceProofVerifier;
use Kukux\DigitalSignature\Tests\Support\TestUser;

/**
 * The package booted in hub mode, with an in-memory personnel directory and
 * a fake DigestSigner. In each test file:
 *
 *   uses(HubApiEnvironment::class);
 *   beforeEach(fn () => $this->setUpHubApi());
 *
 * A trait, not a TestCase subclass: tests/Pest.php already gives every file
 * under Feature/ the package TestCase, and Pest allows one class per file.
 *
 * Testbench registers package providers before getEnvironmentSetUp() runs,
 * and SignatureServiceProvider picks the hub sub-providers in register(), so
 * the mode is set right after configuration loads instead.
 */
trait HubApiEnvironment
{
    public FakePersonnelDirectory $directory;

    public FakeDigestSigner $digestSigner;

    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        $app['config']->set('signature.mode', 'hub');
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('queue.default', 'sync');
        $app['config']->set('signature.hub.specimen_disk', null);
        $app['config']->set('signature.hub.mirrors_disk', null);
    }

    protected function setUpHubApi(): void
    {
        Storage::fake('testing');

        $this->directory = new FakePersonnelDirectory;
        $this->app->instance(PersonnelDirectory::class, $this->directory);

        $this->digestSigner = new FakeDigestSigner;
        $this->app->instance(DigestSigner::class, $this->digestSigner);
    }

    // ── Apps and tokens ───────────────────────────────────────────────────

    /** @return array{app: HubApp, client_secret: string, webhook_secret: string} */
    public function registerApp(string $clientId = 'performance', array $scopes = HubAppRegistrar::SCOPES, ?string $webhook = 'default'): array
    {
        return app(HubAppRegistrar::class)->create(
            $clientId,
            Str::headline($clientId),
            ["https://{$clientId}.uplb.test/signature/hub/callback"],
            $webhook === 'default' ? "https://{$clientId}.uplb.test/signature/hub/webhook" : $webhook,
            $scopes,
        );
    }

    public function appToken(HubApp $app): string
    {
        return app(HubOAuthServer::class)->issueAppToken($app)['access_token'];
    }

    /** @return array<string, string> */
    public function bearer(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    // ── People, accounts and specimens ────────────────────────────────────

    public function person(string $key = 'p-juan', array $overrides = []): Personnel
    {
        return $this->directory->add(new Personnel(
            key: $key,
            name: $overrides['name'] ?? 'Juan dela Cruz',
            empNo: $overrides['empNo'] ?? 'E-1001',
            email: $overrides['email'] ?? 'jdcruz@up.edu.ph',
            unit: $overrides['unit'] ?? 'ICS',
            position: $overrides['position'] ?? 'Assistant Professor',
            active: $overrides['active'] ?? true,
        ));
    }

    /** A hub account linked to a person, verified unless told otherwise. */
    public function account(string $key = 'p-juan', int $userId = 42, string $status = Identity::VERIFIED): TestUser
    {
        $user = TestUser::create(['id' => $userId, 'name' => 'Juan dela Cruz', 'email' => "user{$userId}@up.edu.ph"]);

        Identity::create([
            'user_id'       => $user->id,
            'personnel_key' => $key,
            'status'        => $status,
            'claimed_at'    => now(),
            'verified_at'   => $status === Identity::VERIFIED ? now() : null,
        ]);

        return $user;
    }

    /** An active primary signature whose image is really on the disk. */
    public function specimen(int $userId = 42, ?string $bytes = null): Signature
    {
        $bytes ??= stampablePng();
        $path = "signatures/hub-{$userId}-".Str::random(6).'.png';

        Storage::disk('testing')->put($path, $bytes);

        return Signature::create([
            'uuid'                 => (string) Str::uuid(),
            'user_id'              => $userId,
            'image_path'           => $path,
            'image_hash'           => hash('sha256', $bytes),
            'source'               => 'draw',
            'status'               => 'active',
            'certificate_password' => 'secret',
        ]);
    }

    /** Person + verified account + specimen: someone apps can ask to sign. */
    public function signer(string $key = 'p-juan', int $userId = 42): Signature
    {
        $this->person($key);
        $this->account($key, $userId);

        return $this->specimen($userId);
    }

    // ── The agent ─────────────────────────────────────────────────────────

    /** @return array{device: SigningDevice, key: \OpenSSLAsymmetricKey} */
    public function pairedAgent(int $userId = 42): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        $device = SigningDevice::create([
            'uuid'             => (string) Str::uuid(),
            'user_id'          => $userId,
            'label'            => 'Office MacBook',
            'public_key'       => openssl_pkey_get_details($key)['key'],
            'key_fingerprint'  => bin2hex(random_bytes(32)),
            'algorithm'        => 'ES256',
            'kind'             => 'agent',
            'protection'       => 'secure_enclave',
            'user_presence'    => true,
            'device_type'      => 'laptop',
            'hardware_id_hash' => str_repeat('ab', 32),
            'status'           => 'active',
        ]);

        return ['device' => $device, 'key' => $key];
    }

    /**
     * Drive a job the way the agent does: claim with the link token, then
     * complete with an identity-key proof over the job (Touch ID approved).
     */
    public function agentApproves(array $agent, string $approvalLink): AgentJob
    {
        parse_str((string) parse_url($approvalLink, PHP_URL_QUERY), $query);
        $uuid = basename((string) parse_url($approvalLink, PHP_URL_PATH));

        $jobs = app(AgentJobService::class);
        $jobs->claim($agent['device'], $uuid, $query['t']);

        $job = AgentJob::query()->where('uuid', $uuid)->firstOrFail();
        $message = DeviceProofVerifier::message($job->purpose, $job->nonce, $job->user_id, $job->payload_hash);
        openssl_sign($message, $der, $agent['key'], OPENSSL_ALGO_SHA256);

        $jobs->complete($agent['device'], $uuid, base64_encode($der));

        return $job->refresh();
    }
}
