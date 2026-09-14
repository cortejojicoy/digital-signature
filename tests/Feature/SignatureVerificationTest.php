<?php

use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Tests\Support\TestUser;

/**
 * The page a QR on a printed document resolves to.
 *
 * It is public, so most of what matters is the shape of what it refuses to
 * say. A scan confirms a mark; it must not become a way to learn anything
 * about the document, the signer, or which references exist.
 */
describe('public signature verification', function () {

    beforeEach(function () {
        $this->user = makeUser(41, 'Paolo Rommel P. Sanchez', 'paolo@example.test');

        $this->signature = Signature::create([
            'uuid'       => 'aef7f9a1-1111-2222-3333-444444444444',
            'user_id'    => 41,
            'slot_key'   => 'accepted_by',
            'image_path' => 'signatures/user-41.png',
            'image_hash' => str_repeat('a', 64),
            'source'     => 'draw',
            'status'     => 'signed',
            'signed_at'  => now(),
        ]);
    });

    it('confirms a valid signature to somebody who is not signed in', function () {
        // The whole point: an auditor holding the paper has no account.
        $this->getJson('/signature/verify/aef7f9a1-1111-2222-3333-444444444444')
            ->assertOk()
            ->assertJsonPath('valid', true)
            ->assertJsonPath('signer', 'Paolo Rommel P. Sanchez')
            ->assertJsonPath('role', 'accepted_by')
            ->assertJsonPath('reference', 'aef7f9a1');
    });

    it('renders a page for a phone rather than only JSON', function () {
        $this->get('/signature/verify/aef7f9a1-1111-2222-3333-444444444444')
            ->assertOk()
            ->assertSee('Signature verified')
            ->assertSee('Paolo Rommel P. Sanchez');
    });

    it('never discloses the signer’s email or the document behind it', function () {
        $response = $this->getJson('/signature/verify/aef7f9a1-1111-2222-3333-444444444444');

        $body = $response->json();

        expect($body)->not->toHaveKey('email')
            ->and($body)->not->toHaveKey('document')
            ->and($body)->not->toHaveKey('signed_document_path')
            ->and(json_encode($body))->not->toContain('paolo@example.test');
    });

    it('answers a revoked signature exactly as it answers an unknown one', function () {
        // Distinguishing the two would say whether a reference ever existed.
        $this->signature->update(['status' => 'revoked', 'revoked_at' => now()]);

        $revoked = $this->getJson('/signature/verify/aef7f9a1-1111-2222-3333-444444444444');
        $unknown = $this->getJson('/signature/verify/00000000-0000-0000-0000-000000000000');

        $revoked->assertStatus(404)->assertJsonPath('valid', false);
        $unknown->assertStatus(404)->assertJsonPath('valid', false);

        expect(array_keys($revoked->json()))->toBe(array_keys($unknown->json()))
            ->and($revoked->json('signer'))->toBeNull()
            ->and($unknown->json('signer'))->toBeNull();
    });

    it('refuses a signature that was never actually completed', function () {
        $this->signature->update(['status' => 'pending', 'signed_at' => null]);

        $this->getJson('/signature/verify/aef7f9a1-1111-2222-3333-444444444444')
            ->assertStatus(404)
            ->assertJsonPath('valid', false);
    });

    it('can be switched off entirely', function () {
        config()->set('signature.verify.enabled', false);

        $this->get('/signature/verify/aef7f9a1-1111-2222-3333-444444444444')
            ->assertStatus(404);
    });
});
