<?php

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Agent\AgentJobService;
use Kukux\DigitalSignature\Agent\AgentPairingService;
use Kukux\DigitalSignature\Models\AgentJob;
use Kukux\DigitalSignature\Models\AgentPairing;
use Kukux\DigitalSignature\Models\SignatureAudit;
use Kukux\DigitalSignature\Models\SigningDevice;
use Kukux\DigitalSignature\Security\CertificateRevocationChecker;
use Kukux\DigitalSignature\Security\DeviceProofVerifier;
use Kukux\DigitalSignature\Security\DeviceRegistry;
use Kukux\DigitalSignature\Tests\Support\TestCertificates;
use Kukux\DigitalSignature\Tests\Support\TestUser;

/*
 * Pairing with a key imported from a certificate file (key_source pkcs12,
 * digital-signature-agent pkcs12-key-storage-plan.md §8–9), driven the way
 * the agent drives it: the leaf's own RSA or P-256 key is the identity key,
 * and the claim carries the chain. Revocation is faked: no network in tests.
 */

const CERT_AGENT_VERSION = '1.1.0';

/** A P-256 session key, as the agent makes in the Secure Enclave / TPM. */
function certSessionKey(): array
{
    $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

    return ['key' => $private, 'spki' => preg_replace('/-----[A-Z ]+-----|\s+/', '', openssl_pkey_get_details($private)['key'])];
}

/** RS256 or ES256 over $message, DER for ECDSA, as the agent sends it. */
function certSign(array $key, string $message): string
{
    openssl_sign($message, $signature, $key['key'], OPENSSL_ALGO_SHA256);

    return base64_encode($signature);
}

function certAlgorithm(array $certificate): string
{
    return openssl_pkey_get_details($certificate['key'])['type'] === OPENSSL_KEYTYPE_RSA ? 'RS256' : 'ES256';
}

function certPost($test, string $path, array $body): \Illuminate\Testing\TestResponse
{
    return $test->call('POST', $path, [], [], [], [
        'CONTENT_TYPE'         => 'application/json',
        'HTTP_ACCEPT'          => 'application/json',
        'HTTP_X_AGENT_VERSION' => CERT_AGENT_VERSION,
    ], json_encode($body));
}

/** An authenticated agent call: bearer token + session-key request proof. */
function certCall($test, array $agent, string $method, string $path, ?array $body = null): \Illuminate\Testing\TestResponse
{
    $raw = $body === null ? '' : json_encode($body);
    $timestamp = (string) now()->getTimestamp();
    $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    $payload = hash('sha256', "{$method}|{$path}|{$raw}|{$timestamp}");

    return $test->call($method, $path, [], [], [], array_filter([
        'CONTENT_TYPE'           => $body === null ? null : 'application/json',
        'HTTP_ACCEPT'            => 'application/json',
        'HTTP_X_AGENT_VERSION'   => CERT_AGENT_VERSION,
        'HTTP_AUTHORIZATION'     => 'Bearer '.$agent['token'],
        'HTTP_X_AGENT_TIMESTAMP' => $timestamp,
        'HTTP_X_AGENT_NONCE'     => $nonce,
        'HTTP_X_AGENT_PROOF'     => certSign($agent['session'], DeviceProofVerifier::message('request', $nonce, $agent['user_id'], $payload)),
    ]), $raw);
}

function certUser(int $id = 42, string $name = 'Juan dela Cruz'): TestUser
{
    $user = TestUser::create(['id' => $id, 'name' => $name, 'email' => "user{$id}@example.test"]);
    Auth::login($user);

    return $user;
}

/**
 * Lookup and claim with $certificate's key as the identity key. The claim
 * response is returned unchecked.
 *
 * @param  array<string, mixed>  $overrides  Claim fields to replace (`device` is merged).
 */
function claimWithCertificate($test, TestUser $user, array $certificate, ?array $chain = null, array $overrides = [], ?array $previous = null): array
{
    $session = certSessionKey();
    $identity = $overrides['identity'] ?? $certificate;
    $userCode = app(AgentPairingService::class)->start($user->id)['user_code'];
    $lookup = certPost($test, '/signature/agent/pairings/lookup', ['user_code' => $userCode])->assertOk()->json();
    $bound = hash('sha256', base64_decode($identity['spki']).base64_decode($session['spki']));

    $body = array_merge([
        'user_code'           => $userCode,
        'key_source'          => 'pkcs12',
        'algorithm'           => certAlgorithm($identity),
        'identity_public_key' => $identity['spki'],
        'session_public_key'  => $session['spki'],
        'certificate_chain'   => $chain ?? TestCertificates::chain($certificate, TestCertificates::pki()['intermediate']),
        'protection'          => 'software',
        'user_presence'       => true,
        'attestation'         => null,
        'agent_version'       => CERT_AGENT_VERSION,
        'proof'               => certSign($identity, DeviceProofVerifier::message('register_agent', $lookup['nonce'], $lookup['user_id'], $bound)),
        'replaces'            => $previous === null ? null : [
            'device_uuid' => $previous['device']->uuid,
            'proof'       => certSign($previous['session'], DeviceProofVerifier::message('rebind_agent', $lookup['nonce'], $lookup['user_id'], $bound)),
        ],
    ], array_diff_key($overrides, ['identity' => 1, 'device' => 1]));

    $body['device'] = array_merge([
        'platform' => 'macos', 'os_version' => '15.1.0', 'model' => 'MacBook Pro',
        'model_identifier' => 'Mac15,3', 'form_factor' => 'laptop',
        'label' => 'Juan’s MacBook Pro', 'hardware_id_hash' => str_repeat('ab', 32),
        'device_type' => 'macbook_pro', 'chassis_type' => null, 'virtual' => false,
    ], $overrides['device'] ?? []);

    $response = certPost($test, "/signature/agent/pairings/{$lookup['pairing']}/claim", $body);

    return ['session' => $session, 'lookup' => $lookup, 'response' => $response];
}

/** Claim, confirm on the web, poll for the token. */
function pairWithCertificate($test, TestUser $user, array $certificate, array $overrides = [], ?array $previous = null): array
{
    ['session' => $session, 'lookup' => $lookup, 'response' => $response] = claimWithCertificate($test, $user, $certificate, null, $overrides, $previous);
    $claim = $response->assertOk()->json();

    $device = app(AgentPairingService::class)->confirm(AgentPairing::where('uuid', $lookup['pairing'])->firstOrFail(), $user->id);

    $poll = certPost($test, "/signature/agent/pairings/{$lookup['pairing']}/poll", ['poll_secret' => $claim['poll_secret']])
        ->assertOk()
        ->assertJsonPath('status', 'confirmed')
        ->json();

    return ['session' => $session, 'token' => $poll['token'], 'user_id' => $lookup['user_id'], 'device' => $device, 'poll' => $poll, 'identity' => $certificate];
}

/** Revocation answers $status (good | revoked | unknown); counts the questions. */
function fakeRevocation(string $status = 'good'): CertificateRevocationChecker
{
    $fake = new class extends CertificateRevocationChecker
    {
        public string $status = 'good';

        public int $asked = 0;

        public function __construct() {}

        public function check(string $certPem, string $issuerPem, array $trustPems = []): string
        {
            $this->asked++;

            return $this->status;
        }
    };

    $fake->status = $status;
    app()->instance(CertificateRevocationChecker::class, $fake);

    return $fake;
}

/** A signing job for $user, claimed by $agent; returns its claim payload. */
function claimedJob($test, array $agent, int $userId): array
{
    [$job, $link] = app(AgentJobService::class)->create($userId, 'sign_receipt', 'Accomplishment Report', hash('sha256', 'pdf'));
    parse_str(parse_url($link, PHP_URL_QUERY), $query);

    return certCall($test, $agent, 'POST', "/signature/agent/jobs/{$job->uuid}/claim", ['link_token' => $query['t']])->assertOk()->json();
}

function completeJob($test, array $agent, array $job): \Illuminate\Testing\TestResponse
{
    return certCall($test, $agent, 'POST', "/signature/agent/jobs/{$job['uuid']}/complete", [
        'proof' => certSign($agent['identity'], DeviceProofVerifier::message('sign_receipt', $job['nonce'], $job['user_id'], $job['payload_hash'])),
    ]);
}

beforeEach(function () {
    config([
        'signature.devices.agent.enabled'          => true,
        'signature.devices.pkcs12.enabled'         => true,
        'signature.devices.pkcs12.trusted_roots'   => [TestCertificates::pki()['root_path']],
        'signature.devices.pkcs12.subject_binding' => 'none',
    ]);
    Storage::fake('testing');
    Notification::fake();
    $this->revocation = fakeRevocation('good');
});

describe('lookup', function () {

    it('tells the agent whether this app takes certificates', function () {
        $user = certUser();
        $lookup = fn () => certPost($this, '/signature/agent/pairings/lookup', [
            'user_code' => app(AgentPairingService::class)->start($user->id)['user_code'],
        ])->json('accepts_certificates');

        expect($lookup())->toBeTrue();

        config(['signature.devices.pkcs12.subject_binding' => 'admin_approval']);
        expect($lookup())->toBeFalse();

        config(['signature.devices.pkcs12.enabled' => false, 'signature.devices.pkcs12.subject_binding' => 'none']);
        expect($lookup())->toBeFalse();
    });

    it('is off by default', function () {
        $defaults = require __DIR__.'/../../config/signature.php';

        expect($defaults['devices']['pkcs12']['enabled'])->toBeFalse()
            ->and($defaults['devices']['pkcs12']['subject_binding'])->toBe('none')
            ->and($defaults['devices']['pkcs12']['trusted_roots'])->toHaveCount(1)
            ->and(openssl_x509_fingerprint(
                "-----BEGIN CERTIFICATE-----\n".chunk_split(base64_encode(file_get_contents($defaults['devices']['pkcs12']['trusted_roots'][0])), 64)."-----END CERTIFICATE-----\n",
                'sha256',
            ))->toBe('decd32e1f8bfdcf8543ee1a9a60f1f9462f5c346f999334ee474fe53a3e0f6b9');

        config(['signature.devices.pkcs12.trusted_roots' => $defaults['devices']['pkcs12']['trusted_roots']]);

        expect(app(\Kukux\DigitalSignature\Security\AgentCertificateValidator::class)->roots())->toHaveCount(1)
            ->and(app(\Kukux\DigitalSignature\Security\AgentCertificateValidator::class)->roots()[0]['parsed']['subject']['CN'])->toBe('Philippine Root CA - G2');
    });
});

describe('pairing with a certificate', function () {

    it('pairs a valid chain and keeps the certificate on the device', function () {
        $user = certUser();
        $pki = TestCertificates::pki();
        $agent = pairWithCertificate($this, $user, $pki['leaf']);

        $device = $agent['device']->fresh();
        $parsed = openssl_x509_parse($pki['leaf']['cert']);

        expect($device->kind)->toBe('agent')
            ->and($device->algorithm)->toBe('RS256')
            ->and($device->protection)->toBe('software')
            ->and($device->key_source)->toBe('pkcs12')
            ->and($device->certificate_fingerprint)->toBe($pki['leaf']['fingerprint'])
            ->and($device->certificate_serial)->toBe(strtoupper($parsed['serialNumberHex']))
            ->and($device->certificate_subject)->toBe('CN=Test UP 4, O=Kukux Test, C=PH')
            ->and($device->certificate_issuer)->toBe('CN=Kukux Test Signing CA, O=Kukux Test, C=PH')
            ->and($device->certificate_not_after->getTimestamp())->toBe($parsed['validTo_time_t'])
            ->and($device->certificate_chain)->toBe([$pki['leaf']['der'], $pki['intermediate']['der']])
            ->and($device->certificate_status)->toBe('good')
            ->and($device->certificate_checked_at)->not->toBeNull()
            ->and($device->certificateSummary())->toBe('Certificate · Test UP 4 · until '.$device->certificate_not_after->format('M Y'))
            ->and($device->certificateIssuerName())->toBe('Kukux Test Signing CA')
            ->and(SignatureAudit::where('event', SignatureAudit::AGENT_PAIRED)->first()->context['certificate'])->toBe($pki['leaf']['fingerprint'])
            ->and($this->revocation->asked)->toBe(1);
    });

    it('pairs a P-256 certificate as ES256, and a chain that includes the root', function () {
        $pki = TestCertificates::pki();
        $claim = claimWithCertificate($this, certUser(), $pki['p256'], TestCertificates::chain($pki['p256'], $pki['intermediate'], $pki['root']));

        $claim['response']->assertOk()->assertJsonPath('status', 'awaiting_confirmation');

        expect(AgentPairing::where('uuid', $claim['lookup']['pairing'])->first()->claim['algorithm'])->toBe('ES256');
    });

    it('keeps platform keys platform keys', function () {
        $user = certUser();
        $claim = claimWithCertificate($this, $user, TestCertificates::pki()['leaf'], null, ['key_source' => null, 'certificate_chain' => null]);
        $claim['response']->assertOk();

        $device = app(AgentPairingService::class)->confirm(AgentPairing::where('uuid', $claim['lookup']['pairing'])->firstOrFail(), $user->id);

        expect($device->key_source)->toBe('platform')
            ->and($device->certificate_fingerprint)->toBeNull()
            ->and($device->usesCertificate())->toBeFalse()
            ->and($this->revocation->asked)->toBe(0);
    });

    it('keeps how each use is approved: the certificate password, or the OS by default', function () {
        config(['signature.devices.agent.require_presence' => true]);
        $pki = TestCertificates::pki();

        $password = pairWithCertificate($this, certUser(), $pki['leaf'], ['presence_method' => 'certificate_password'])['device']->fresh();
        $os = pairWithCertificate($this, certUser(43, 'Maria Santos'), $pki['p256'], ['device' => ['hardware_id_hash' => str_repeat('cd', 32)]])['device']->fresh();

        expect($password->presence_method)->toBe('certificate_password')
            ->and($password->user_presence)->toBeTrue()
            ->and($os->presence_method)->toBe('os');

        Auth::login(TestUser::find(42));

        \Livewire\Livewire::test(\Kukux\DigitalSignature\Filament\Livewire\SigningDevices::class)
            ->assertSee('Approved with certificate password');
    });

    it('shows the certificate in the confirm prompt and the devices list', function () {
        $user = certUser();

        $component = \Livewire\Livewire::test(\Kukux\DigitalSignature\Filament\Livewire\SigningDevices::class)->call('startPairing');
        $pki = TestCertificates::pki();
        $session = certSessionKey();
        $lookup = certPost($this, '/signature/agent/pairings/lookup', ['user_code' => $component->get('userCode')])->json();
        $bound = hash('sha256', base64_decode($pki['leaf']['spki']).base64_decode($session['spki']));

        certPost($this, "/signature/agent/pairings/{$lookup['pairing']}/claim", [
            'user_code' => $component->get('userCode'), 'key_source' => 'pkcs12', 'algorithm' => 'RS256',
            'identity_public_key' => $pki['leaf']['spki'], 'session_public_key' => $session['spki'],
            'certificate_chain' => TestCertificates::chain($pki['leaf'], $pki['intermediate']),
            'protection' => 'software', 'user_presence' => true, 'agent_version' => CERT_AGENT_VERSION,
            'device' => ['platform' => 'macos', 'os_version' => '15.1.0', 'model' => 'MacBook Pro', 'label' => 'Work Mac', 'form_factor' => 'laptop'],
            'proof' => certSign($pki['leaf'], DeviceProofVerifier::message('register_agent', $lookup['nonce'], '42', $bound)),
        ])->assertOk();

        $until = now()->addDays(365)->format('M Y');

        $component->call('refreshPairing')
            ->assertSee('Pair Work Mac?')
            ->assertSee("Certificate: Test UP 4 · Kukux Test Signing CA · until {$until}")
            ->call('confirmPairing')
            ->assertSee('Paired Work Mac')
            ->assertSee("Certificate · Test UP 4 · until {$until} · issued by Kukux Test Signing CA");
    });
});

describe('refusals', function () {

    it('refuses certificates when the app has not opted in', function () {
        config(['signature.devices.pkcs12.enabled' => false]);

        claimWithCertificate($this, certUser(), TestCertificates::pki()['leaf'])['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'certificates_disabled');
    });

    it('refuses a certificate password on a platform key, and an unknown presence method', function () {
        $leaf = TestCertificates::pki()['leaf'];

        claimWithCertificate($this, certUser(), $leaf, null, ['key_source' => null, 'certificate_chain' => null, 'presence_method' => 'certificate_password'])['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_presence_method');

        claimWithCertificate($this, TestUser::find(42), $leaf, null, ['presence_method' => 'pin'])['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'invalid_presence_method');

        expect(AgentPairing::where('status', 'awaiting_confirmation')->exists())->toBeFalse();
    });

    it('refuses certificates under admin_approval, which is not built', function () {
        config(['signature.devices.pkcs12.subject_binding' => 'admin_approval']);

        claimWithCertificate($this, certUser(), TestCertificates::pki()['leaf'])['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'certificates_disabled');
    });

    it('refuses a chain this app does not trust', function (string $case, string $message) {
        $pki = TestCertificates::pki();

        [$certificate, $chain] = match ($case) {
            'another root'        => [$pki['stranger'], TestCertificates::chain($pki['stranger'], $pki['other_root'])],
            'no issuer sent'      => [$pki['leaf'], TestCertificates::chain($pki['leaf'])],
            'links out of order'  => [$pki['leaf'], TestCertificates::chain($pki['leaf'], $pki['root'], $pki['intermediate'])],
            'not a certificate'   => [$pki['leaf'], [base64_encode('not der'), $pki['intermediate']['der']]],
            'empty chain'         => [$pki['leaf'], []],
            'signing not allowed' => [$pki['encipher'], TestCertificates::chain($pki['encipher'], $pki['intermediate'])],
            'a CA certificate'    => [$pki['ca_leaf'], TestCertificates::chain($pki['ca_leaf'], $pki['intermediate'])],
        };

        claimWithCertificate($this, certUser(), $certificate, $chain)['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'certificate_untrusted')
            ->assertJsonPath('error.message', $message);

        expect(AgentPairing::where('status', 'awaiting_confirmation')->exists())->toBeFalse();
    })->with([
        ['another root', 'The certificate is not from an issuer this app trusts.'],
        ['no issuer sent', 'The certificate is not from an issuer this app trusts.'],
        ['links out of order', 'The certificate chain is broken.'],
        ['not a certificate', 'The certificate chain could not be read.'],
        ['empty chain', 'No certificate chain was sent.'],
        ['signing not allowed', "This certificate isn't for signing (its key usage is Key Encipherment)."],
        ['a CA certificate', 'The certificate sent is a CA certificate, not a personal one.'],
    ]);

    it("refuses a chain whose leaf isn't the identity key", function () {
        $pki = TestCertificates::pki();

        // The proof is good, by the second certificate's key; the chain is the first's.
        claimWithCertificate($this, certUser(), $pki['leaf'], null, ['identity' => $pki['second']])['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'certificate_untrusted')
            ->assertJsonPath('error.message', "The certificate's key is not the identity key.");
    });

    it('refuses an expired certificate', function () {
        $this->travel(400)->days();

        claimWithCertificate($this, certUser(), TestCertificates::pki()['leaf'])['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'certificate_expired');
    });

    it('refuses a revoked certificate', function () {
        $this->revocation->status = 'revoked';

        claimWithCertificate($this, certUser(), TestCertificates::pki()['leaf'])['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'certificate_revoked');

        expect(SigningDevice::count())->toBe(0);
    });

    it('pairs when no revocation source answers, unless told to deny', function () {
        $this->revocation->status = 'unknown';
        $user = certUser();

        $device = pairWithCertificate($this, $user, TestCertificates::pki()['leaf'])['device']->fresh();

        expect($device->certificate_status)->toBe('unknown')
            ->and($device->certificate_checked_at)->toBeNull();

        config(['signature.devices.pkcs12.unreachable' => 'deny']);
        app(DeviceRegistry::class)->revoke($device);

        claimWithCertificate($this, $user, TestCertificates::pki()['leaf'])['response']
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'revocation_unavailable');
    });

    it('binds the certificate to the account by name when asked', function () {
        config(['signature.devices.pkcs12.subject_binding' => 'name_match']);

        claimWithCertificate($this, certUser(42, 'Juan dela Cruz'), TestCertificates::pki()['leaf'])['response']
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'certificate_subject_mismatch')
            ->assertJsonPath('error.message', 'This certificate is for Test UP 4, not Juan dela Cruz.');

        claimWithCertificate($this, certUser(43, 'TEST UP-4'), TestCertificates::pki()['leaf'])['response']
            ->assertOk();
    });
});

describe('one computer per certificate', function () {

    it('refuses the certificate on a second computer, naming the first', function () {
        $pki = TestCertificates::pki();
        pairWithCertificate($this, certUser(42), $pki['leaf']);

        // Another account, on another computer, with a copy of the same file.
        $claim = claimWithCertificate($this, certUser(43, 'Maria Clara'), $pki['leaf'], null, [
            'device' => ['hardware_id_hash' => str_repeat('cd', 32), 'label' => 'Maria’s iMac'],
        ]);

        $claim['response']->assertStatus(409)
            ->assertJsonPath('error.code', 'certificate_in_use')
            ->assertJsonPath('device', ['label' => 'Juan’s MacBook Pro', 'device_type' => 'macbook_pro']);
        expect($claim['response']->json('error.message'))->toContain('Juan’s MacBook Pro')
            ->and(array_keys($claim['response']->json()))->toBe(['error', 'device']);
    });

    it('lets the same computer pair the certificate again', function () {
        $user = certUser();
        $pki = TestCertificates::pki();
        $first = pairWithCertificate($this, $user, $pki['leaf']);

        $again = pairWithCertificate($this, $user, $pki['leaf'], previous: $first);

        expect($again['device']->id)->toBe($first['device']->id)
            ->and($again['poll']['rebound'])->toBeTrue()
            ->and($again['device']->fresh()->key_source)->toBe('pkcs12');
    });

    it('pairs a removed certificate again, on its old row', function () {
        $user = certUser();
        $pki = TestCertificates::pki();
        $first = pairWithCertificate($this, $user, $pki['leaf'])['device'];
        app(DeviceRegistry::class)->revoke($first);

        $again = pairWithCertificate($this, $user, $pki['leaf'], ['device' => ['hardware_id_hash' => str_repeat('cd', 32), 'label' => 'New Mac']])['device'];

        expect($again->id)->toBe($first->id)
            ->and($again->fresh()->status)->toBe('active')
            ->and($again->fresh()->label)->toBe('New Mac')
            ->and(SigningDevice::where('user_id', $user->id)->count())->toBe(1);
    });

    it('checks the certificate again on confirm', function () {
        $pki = TestCertificates::pki();
        $claim = claimWithCertificate($this, certUser(42), $pki['leaf']);
        $claim['response']->assertOk();

        // Another account paired a copy between the claim and the confirm.
        pairWithCertificate($this, certUser(43, 'Maria Clara'), $pki['leaf'], ['device' => ['hardware_id_hash' => str_repeat('cd', 32), 'label' => 'Maria’s iMac']]);

        expect(fn () => app(AgentPairingService::class)->confirm(AgentPairing::where('uuid', $claim['lookup']['pairing'])->firstOrFail(), 42))
            ->toThrow(InvalidArgumentException::class, 'This certificate is already paired on Maria’s iMac.');
    });

    it('allows copies when one_device_per_certificate is off', function () {
        config(['signature.devices.pkcs12.one_device_per_certificate' => false]);
        $pki = TestCertificates::pki();
        pairWithCertificate($this, certUser(42), $pki['leaf']);

        claimWithCertificate($this, certUser(43, 'Maria Clara'), $pki['leaf'], null, ['device' => ['hardware_id_hash' => str_repeat('cd', 32)]])['response']
            ->assertOk();
    });
});

describe('signing with a certificate', function () {

    it('records the certificate on the approval, and asks again only after recheck_ttl', function () {
        $user = certUser();
        $agent = pairWithCertificate($this, $user, TestCertificates::pki()['leaf']);

        completeJob($this, $agent, $job = claimedJob($this, $agent, $user->id))
            ->assertOk()
            ->assertExactJson(['status' => 'completed', 'certificate_status' => 'good']);

        expect($this->revocation->asked)->toBe(1)
            ->and(AgentJob::where('uuid', $job['uuid'])->first()->meta['certificate']['fingerprint'])->toBe($agent['device']->certificate_fingerprint)
            ->and(SignatureAudit::where('event', SignatureAudit::AGENT_APPROVED)->first()->context['certificate'])->toBe($agent['device']->certificate_fingerprint);

        $this->travel(2)->days();
        completeJob($this, $agent, claimedJob($this, $agent, $user->id))->assertOk();

        expect($this->revocation->asked)->toBe(2)
            ->and($agent['device']->fresh()->certificate_checked_at->isToday())->toBeTrue();
    });

    it('revokes the device when the certificate is revoked', function () {
        $user = certUser();
        $agent = pairWithCertificate($this, $user, TestCertificates::pki()['leaf']);

        $this->revocation->status = 'revoked';
        $this->travel(2)->days();
        $job = claimedJob($this, $agent, $user->id);

        completeJob($this, $agent, $job)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'certificate_revoked');

        $device = $agent['device']->fresh();

        expect($device->status)->toBe('revoked')
            ->and($device->certificate_status)->toBe('revoked')
            ->and(AgentJob::where('uuid', $job['uuid'])->value('status'))->toBe('rejected')
            ->and(AgentJob::where('uuid', $job['uuid'])->value('reason'))->toBe('certificate_revoked')
            ->and(SignatureAudit::where('event', SignatureAudit::AGENT_CERTIFICATE_REVOKED)->where('device_id', $device->id)->exists())->toBeTrue();

        // Its token went with it.
        certCall($this, $agent, 'GET', '/signature/agent/status')->assertStatus(401);
    });

    it('refuses to complete once the certificate has expired', function () {
        $user = certUser();
        $agent = pairWithCertificate($this, $user, TestCertificates::pki()['leaf']);

        $this->travel(400)->days();
        $job = claimedJob($this, $agent, $user->id);

        completeJob($this, $agent, $job)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'certificate_expired');

        expect(AgentJob::where('uuid', $job['uuid'])->value('status'))->toBe('rejected')
            ->and($agent['device']->fresh()->status)->toBe('active');
    });
});
