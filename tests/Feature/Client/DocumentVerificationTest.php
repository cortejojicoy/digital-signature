<?php

use Illuminate\Support\Facades\Http;
use Kukux\DigitalSignature\Tests\Feature\Client\ClientTestCase;

/**
 * The verify-a-PDF page in client mode.
 *
 * The page itself works the same everywhere: the record it looks fingerprints
 * up in is this app's own. What differs is the CA. Signatures are made at the
 * hub, so the certificate the browser checks chains against is the hub's,
 * and this app relays it rather than pretending to have one.
 */
uses(ClientTestCase::class);

beforeEach(fn () => $this->setUpClient());

const HUB_CA = "-----BEGIN CERTIFICATE-----\nMIIBhub\n-----END CERTIFICATE-----\n";

it('is available in client mode', function () {
    $this->get('/signature/verify')->assertOk()->assertSee('Verify a signed document');
    $this->postJson('/signature/verify/lookup', ['sha256' => str_repeat('a', 64)])
        ->assertOk()
        ->assertJsonPath('verdict', 'not_issued');
});

it('relays the hub\'s CA certificate, and asks the hub only once a day', function () {
    $this->fakeHub(['hub.test/signature/trust/ca.pem' => Http::response(HUB_CA)]);

    expect($this->get('/signature/trust/ca.pem')->assertOk()->getContent())->toBe(HUB_CA);
    $this->get('/signature/trust/ca.pem')->assertOk();

    Http::assertSentCount(1);
});

it('answers not found when the hub has no CA to give, rather than inventing one', function () {
    $this->fakeHub(['hub.test/signature/trust/ca.pem' => Http::response('Server Error', 500)]);

    $this->get('/signature/trust/ca.pem')->assertNotFound();
});

it('treats a hub that answers with something other than a certificate as no CA', function () {
    $this->fakeHub(['hub.test/signature/trust/ca.pem' => Http::response('<html>login</html>')]);

    $this->get('/signature/trust/ca.pem')->assertNotFound();
});
