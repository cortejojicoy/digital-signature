<?php

namespace Kukux\DigitalSignature\Tests\Feature\Hub;

use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelRegistry;
use Illuminate\Cookie\CookieValuePrefix;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Kukux\DigitalSignature\Agent\AgentPairingService;
use Kukux\DigitalSignature\Models\AgentPairing;
use Kukux\DigitalSignature\Models\Identity;
use Kukux\DigitalSignature\Security\DeviceProofVerifier;
use Kukux\DigitalSignature\SignaturePlugin;
use Kukux\DigitalSignature\Tests\Feature\Hub\Identity\Support\HubTestPersonnel;
use Kukux\DigitalSignature\Tests\Feature\Hub\Identity\Support\HubTestUser;
use ReflectionClass;

/**
 * Hub mode for the identity tests (tests/Feature/Hub/Identity).
 *
 * A trait rather than a TestCase subclass: tests/Pest.php already gives every
 * file under Feature/ the package TestCase, and Pest refuses a second class
 * for the same file. `uses(HubIdentityTestCase::class)` in each file adds
 * this on top of it.
 *
 * It switches signature.mode to hub before the providers register (the hub
 * sub-providers are chosen in register()), the same way the hub API tests'
 * HubApiEnvironment does, maps an in-memory personnel table,
 * and carries the agent helpers of tests/Feature/AgentProtocolTest.php as
 * methods, so these files run on their own too.
 */
trait HubIdentityTestCase
{
    /**
     * Right after configuration loads: Testbench registers package providers
     * before getEnvironmentSetUp() runs, and SignatureServiceProvider picks
     * the hub sub-providers in register().
     */
    protected function resolveApplicationConfiguration($app)
    {
        parent::resolveApplicationConfiguration($app);

        // Only the mode: anything else under `signature` set this early
        // would replace the package's whole block at mergeConfigFrom().
        $app['config']->set('signature.mode', 'hub');
    }

    /** Called after TestCase::getEnvironmentSetUp, which points the user model at TestUser. */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('signature.devices.agent.enabled', true);
        $app['config']->set('auth.providers.users.model', HubTestUser::class);
        $app['config']->set('signature.hub.personnel.model', HubTestPersonnel::class);
        $app['config']->set('signature.hub.personnel.columns', [
            'key'      => 'uuid',
            'emp_no'   => 'employee_number',
            'name'     => 'full_name',
            'email'    => 'email',
            'unit'     => 'unit_name',
            'position' => 'position_title',
            'active'   => 'is_active',
        ]);
        $app['config']->set('app.name', 'UPLB Signature');
    }

    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::table('users', fn ($t) => $t->string('roles')->nullable());

        Schema::create('hub_test_personnel', function ($t) {
            $t->id();
            $t->string('uuid')->unique();
            $t->string('employee_number')->unique();
            $t->string('full_name');
            $t->string('email')->nullable();
            $t->string('unit_name')->nullable();
            $t->string('position_title')->nullable();
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    public function person(string $name, string $empNo, array $extra = []): HubTestPersonnel
    {
        return HubTestPersonnel::create($extra + [
            'uuid'            => 'p-'.Str::slug($name),
            'employee_number' => $empNo,
            'full_name'       => $name,
            'email'           => Str::slug($name, '.').'@up.edu.ph',
            'unit_name'       => 'Institute of Computer Science',
            'position_title'  => 'Assistant Professor',
            'is_active'       => true,
        ]);
    }

    public function hubUser(int $id, string $name = 'Someone', array $extra = []): HubTestUser
    {
        return HubTestUser::create($extra + ['id' => $id, 'name' => $name, 'email' => "user{$id}@example.test"]);
    }

    /** An account already linked to a person, in the given state. */
    public function identified(HubTestUser $user, HubTestPersonnel $person, string $status = Identity::VERIFIED): Identity
    {
        return Identity::create([
            'user_id'       => $user->id,
            'personnel_key' => $person->uuid,
            'status'        => $status,
            'claimed_at'    => now(),
            'verified_at'   => $status === Identity::VERIFIED ? now() : null,
        ]);
    }

    // ── Browsers ─────────────────────────────────────────────────────────────

    /** @var array<string, string>  browser name → its session id */
    public array $browserSessions = [];

    /**
     * A JSON call from a named browser: it keeps its own session cookie
     * between calls, and nobody else's, so "only the browser that started
     * it" can be tested. The auth guard forgets its cached user between
     * calls, as a fresh PHP request would.
     * Sessions live in the array handler, which outlives the store.
     */
    public function fromBrowser(string $browser, string $method, string $uri, array $data = [], array $server = []): TestResponse
    {
        $name = config('session.cookie');
        $cookies = isset($this->browserSessions[$browser])
            ? [$name => encrypt(CookieValuePrefix::create($name, app('encrypter')->getKey()).$this->browserSessions[$browser], false)]
            : [];

        // A fresh session store and guard, as a new PHP request gets: the
        // test app keeps both alive between calls otherwise.
        $handler = app('session')->driver()->getHandler();
        app('session')->forgetDrivers();
        app()->forgetInstance('session.store');
        app('session')->driver()->setHandler($handler);
        app('auth')->forgetGuards();

        $response = $this->call($method, $uri, $data, $cookies, [], $server + [
            'HTTP_ACCEPT'           => 'application/json',
            'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest',
        ]);

        if ($cookie = $response->getCookie($name)) {
            $this->browserSessions[$browser] = $cookie->getValue();
        }

        return $response;
    }

    /** The user a browser's session is signed in as, if any. */
    public function browserUserId(string $browser): ?int
    {
        $data = app('session')->driver()->getHandler()->read($this->browserSessions[$browser] ?? '');
        $session = $data === '' ? [] : (@unserialize($data) ?: []);
        $id = $session[app('auth')->guard()->getName()] ?? null;

        return $id === null ? null : (int) $id;
    }

    // ── Agent (as tests/Feature/AgentProtocolTest.php drives it) ─────────────

    public function agentKeyPair(): array
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $spki = preg_replace('/-----[A-Z ]+-----|\s+/', '', openssl_pkey_get_details($private)['key']);

        return ['private' => $private, 'spki' => $spki];
    }

    public function agentSignature(array $key, string $message): string
    {
        openssl_sign($message, $der, $key['private'], OPENSSL_ALGO_SHA256);

        return base64_encode($der);
    }

    /** An unauthenticated agent call (pairing). */
    public function agentJson(string $path, array $body): TestResponse
    {
        return $this->call('POST', $path, [], [], [], [
            'CONTENT_TYPE'         => 'application/json',
            'HTTP_ACCEPT'          => 'application/json',
            'HTTP_X_AGENT_VERSION' => '1.0.0',
        ], json_encode($body));
    }

    /** An authenticated agent call: bearer token + session-key request proof. */
    public function agentRequest(array $agent, string $method, string $path, ?array $body = null): TestResponse
    {
        $raw = $body === null ? '' : json_encode($body);
        $timestamp = (string) now()->getTimestamp();
        $nonce = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
        $payload = hash('sha256', "{$method}|{$path}|{$raw}|{$timestamp}");
        $proof = $this->agentSignature($agent['session'], DeviceProofVerifier::message('request', $nonce, $agent['user_id'], $payload));

        return $this->call($method, $path, [], [], [], array_filter([
            'CONTENT_TYPE'           => $body === null ? null : 'application/json',
            'HTTP_ACCEPT'            => 'application/json',
            'HTTP_X_AGENT_VERSION'   => '1.0.0',
            'HTTP_AUTHORIZATION'     => 'Bearer '.$agent['token'],
            'HTTP_X_AGENT_TIMESTAMP' => $timestamp,
            'HTTP_X_AGENT_NONCE'     => $nonce,
            'HTTP_X_AGENT_PROOF'     => $proof,
        ]), $raw);
    }

    /**
     * The agent's half of pairing for a code started elsewhere: lookup, then
     * claim. Returns the keys and the pairing uuid; the claim response is
     * asserted OK.
     */
    public function agentClaims(string $userCode, array $device = []): array
    {
        $identity = $this->agentKeyPair();
        $session = $this->agentKeyPair();

        $lookup = $this->agentJson('/signature/agent/pairings/lookup', ['user_code' => $userCode])->assertOk()->json();
        $bound = hash('sha256', base64_decode($identity['spki']).base64_decode($session['spki']));

        $claim = $this->agentJson("/signature/agent/pairings/{$lookup['pairing']}/claim", [
            'user_code'           => $userCode,
            'algorithm'           => 'ES256',
            'identity_public_key' => $identity['spki'],
            'session_public_key'  => $session['spki'],
            'protection'          => 'secure_enclave',
            'user_presence'       => true,
            'device'              => $device + [
                'platform' => 'macos', 'os_version' => '15.1.0', 'model' => 'MacBook Air',
                'model_identifier' => 'Mac15,12', 'form_factor' => 'laptop',
                'label' => 'Juan’s MacBook Air', 'hardware_id_hash' => str_repeat('ab', 32),
            ],
            'agent_version'       => '1.0.0',
            'proof'               => $this->agentSignature($identity, DeviceProofVerifier::message('register_agent', $lookup['nonce'], $lookup['user_id'], $bound)),
        ])->assertOk()->json();

        return ['identity' => $identity, 'session' => $session, 'pairing' => $lookup['pairing'], 'user_id' => $lookup['user_id'], 'poll_secret' => $claim['poll_secret']];
    }

    /** The agent picks up its token after the browser confirmed. */
    public function agentPollsToken(array $agent): array
    {
        $poll = $this->agentJson("/signature/agent/pairings/{$agent['pairing']}/poll", ['poll_secret' => $agent['poll_secret']])
            ->assertOk()->assertJsonPath('status', 'confirmed')->json();

        return $agent + ['token' => $poll['token']];
    }

    /** A user's computer, paired end to end (web start → agent → confirm → token). */
    public function pairComputer(HubTestUser $user, array $device = []): array
    {
        $code = app(AgentPairingService::class)->start($user->id)['user_code'];
        $agent = $this->agentClaims($code, $device);

        $agent['device'] = app(AgentPairingService::class)->confirm(AgentPairing::where('uuid', $agent['pairing'])->firstOrFail(), $user->id);

        return $this->agentPollsToken($agent);
    }

    // ── Panels ───────────────────────────────────────────────────────────────

    /**
     * The hub app's two panels, as docs/hub/panels.md tells it to register
     * them, with Filament's own routes. Registered per test: the panel
     * routes file reads the panel list when it is loaded.
     *
     * @return array{0: Panel, 1: Panel}
     */
    public function hubPanels(): array
    {
        foreach ([
            \RyanChandler\BladeCaptureDirective\BladeCaptureDirectiveServiceProvider::class,
            \Filament\Actions\ActionsServiceProvider::class,
            \Filament\Forms\FormsServiceProvider::class,
            \Filament\Infolists\InfolistsServiceProvider::class,
            \Filament\Notifications\NotificationsServiceProvider::class,
            \Filament\Schemas\SchemasServiceProvider::class,
            \Filament\Tables\TablesServiceProvider::class,
            \Filament\Widgets\WidgetsServiceProvider::class,
        ] as $provider) {
            if (class_exists($provider)) {
                app()->register($provider);
            }
        }

        $middleware = [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            ShareErrorsFromSession::class,
            SubstituteBindings::class,
            DisableBladeIconComponents::class,
            DispatchServingFilamentEvent::class,
        ];

        $person = Panel::make()->id('hub')->path('')->default()
            ->topbar(false)->navigation(false)
            ->middleware($middleware)->authMiddleware([Authenticate::class])
            ->plugin(SignaturePlugin::make()->hubPersonPanel());

        $admin = Panel::make()->id('admin')->path('admin')
            ->middleware($middleware)->authMiddleware([Authenticate::class])
            ->plugin(SignaturePlugin::make()->hubAdminPanel());

        app(PanelRegistry::class)->register($person);
        app(PanelRegistry::class)->register($admin);

        require dirname((new ReflectionClass(\Filament\FilamentServiceProvider::class))->getFileName(), 2).'/routes/web.php';
        app('router')->getRoutes()->refreshNameLookups();
        app('router')->getRoutes()->refreshActionLookups();

        Filament::setCurrentPanel($person);
        $person->boot();

        return [$person, $admin];
    }
}
