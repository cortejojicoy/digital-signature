<?php

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kukux\DigitalSignature\Client\Exceptions\HubException;
use Kukux\DigitalSignature\Client\Exceptions\HubNotFoundException;
use Kukux\DigitalSignature\Client\Exceptions\HubRefusedException;
use Kukux\DigitalSignature\Client\Exceptions\HubUnavailableException;
use Kukux\DigitalSignature\Client\Exceptions\SpecimenChangedException;
use Kukux\DigitalSignature\Client\HubClient;
use Kukux\DigitalSignature\Tests\Feature\Client\ClientTestCase;

/**
 * The app's side of the hub API: one cached app token, a breaker that stops
 * every page waiting on a dead hub, and errors as types.
 */
uses(ClientTestCase::class);

beforeEach(fn () => $this->setUpClient());

describe('HubClient', function () {

    it('fetches the app token once and reuses it until it expires', function () {
        $this->fakeHub([
            'hub.test/signature/hub/api/v1/people/p-juan' => Http::response(['sub' => 'p-juan', 'active' => true]),
        ]);

        $hub = app(HubClient::class);
        $hub->person('p-juan');
        $hub->person('p-juan');

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/oauth/token')
            && $r['grant_type'] === 'client_credentials'
            && $r['client_id'] === 'performance'
            && $r['client_secret'] === 'client-secret');
        Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/people/p-juan') && $r->hasHeader('Authorization', 'Bearer app-token'));

        $this->travel(3600)->seconds();
        $hub->person('p-juan');

        Http::assertSentCount(5);
    });

    it('drops a rejected token and retries once with a new one', function () {
        $calls = 0;
        $this->fakeHub([
            'hub.test/signature/hub/api/v1/people/p-juan' => function () use (&$calls) {
                return ++$calls === 1
                    ? Http::response(['error' => 'invalid_token', 'message' => 'Expired.'], 401)
                    : Http::response(['sub' => 'p-juan']);
            },
        ]);

        expect(app(HubClient::class)->person('p-juan')['sub'])->toBe('p-juan');
        Http::assertSentCount(4); // token, 401, token, 200
    });

    it('maps hub errors to their own exceptions', function (int $status, array $body, string $class) {
        $this->fakeHub(['hub.test/signature/hub/api/v1/sign-requests' => Http::response($body, $status)]);

        try {
            app(HubClient::class)->createSignRequest(['sub' => 'p-juan']);
        } catch (HubException $e) {
            expect($e)->toBeInstanceOf($class)
                ->and($e->errorCode)->toBe($body['error'])
                ->and($e->getMessage())->toBe($body['message']);

            if ($e instanceof SpecimenChangedException) {
                expect($e->currentSpecimenHash)->toBe(str_repeat('e', 64));
            }

            return;
        }

        $this->fail('No exception thrown.');
    })->with([
        'specimen changed' => [409, ['error' => 'specimen_changed', 'message' => 'Changed.', 'current_specimen_hash' => str_repeat('e', 64)], SpecimenChangedException::class],
        'not found'        => [404, ['error' => 'unknown_person', 'message' => 'Who?'], HubNotFoundException::class],
        'not verified'     => [422, ['error' => 'not_verified', 'message' => 'Not verified.'], HubRefusedException::class],
        'not linked'       => [403, ['error' => 'not_linked', 'message' => 'Not a holder.'], HubRefusedException::class],
        'server error'     => [503, ['error' => 'degraded', 'message' => 'Down.'], HubUnavailableException::class],
    ]);

    it('opens the breaker after repeated failures, logs it once, and fails fast', function () {
        Log::spy();
        $attempts = 0;
        $down = true;

        Http::fake(function ($request) use (&$attempts, &$down) {
            if (! $down) {
                return str_ends_with($request->url(), '/oauth/token')
                    ? Http::response(['access_token' => 'app-token', 'expires_in' => 3600])
                    : Http::response(['uuid' => 'u', 'status' => 'active', 'image_sha256' => 'x']);
            }

            $attempts++;

            throw new ConnectionException('Connection refused');
        });

        $hub = app(HubClient::class);

        foreach (range(1, 5) as $_) {
            try {
                $hub->signature('p-juan');
            } catch (HubUnavailableException) {
            }
        }

        // Three real attempts (the token call each time), then fast failures.
        expect($attempts)->toBe(3)->and($hub->isOpen())->toBeTrue();
        Log::shouldHaveReceived('warning')->with('hub.unavailable', Mockery::type('array'))->once();

        // After the cool-down the next call goes through, and success closes it.
        $this->travel(61)->seconds();
        $down = false;

        expect($hub->signature('p-juan')['uuid'])->toBe('u')->and($hub->isOpen())->toBeFalse();
    });

    it('sends If-None-Match and treats 304 as unchanged', function () {
        $this->fakeHub(['hub.test/signature/hub/api/v1/people/p-juan/signature/image' => Http::response('', 304)]);

        expect(app(HubClient::class)->image('p-juan', str_repeat('a', 64)))->toBeNull();
        Http::assertSent(fn (Request $r) => $r->hasHeader('If-None-Match', '"'.str_repeat('a', 64).'"'));
    });

    it('looks people up by one exact identifier', function () {
        $this->fakeHub(['hub.test/signature/hub/api/v1/people*' => Http::response(['data' => [['sub' => 'p-juan', 'email' => 'j@up.edu.ph']]])]);

        expect(app(HubClient::class)->findPerson(email: 'j@up.edu.ph', empNo: 'E-1')['sub'])->toBe('p-juan');
        Http::assertSent(fn (Request $r) => str_contains($r->url(), 'people?emp_no=E-1') && ! str_contains($r->url(), 'email'));
    });
});
