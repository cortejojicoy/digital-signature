<?php

use Kukux\DigitalSignature\Models\Signature;
use Kukux\DigitalSignature\Models\UserCertificate;

describe('Signature model', function () {

    it('isPending returns true for pending status', function () {
        $sig = new Signature(['status' => 'pending']);
        expect($sig->isPending())->toBeTrue()
            ->and($sig->isSigned())->toBeFalse()
            ->and($sig->isRevoked())->toBeFalse();
    });

    it('isSigned returns true for signed status', function () {
        $sig = new Signature(['status' => 'signed']);
        expect($sig->isSigned())->toBeTrue();
    });

    it('casts pades_info as array', function () {
        $sig = new Signature(['pades_info' => ['reason' => 'Approved']]);
        expect($sig->pades_info)->toBeArray()->toHaveKey('reason');
    });
});

describe('UserCertificate model', function () {

    it('isExpired returns true when expires_at is in the past', function () {
        $cert = new UserCertificate(['expires_at' => now()->subDay()]);
        expect($cert->isExpired())->toBeTrue();
    });

    it('isValid returns false when both expired and revoked', function () {
        $cert = new UserCertificate([
            'expires_at' => now()->subDay(),
            'revoked_at' => now()->subHour(),
        ]);
        expect($cert->isValid())->toBeFalse();
    });

    it('isValid returns true for a fresh certificate', function () {
        $cert = new UserCertificate([
            'expires_at' => now()->addYear(),
            'revoked_at' => null,
        ]);
        expect($cert->isValid())->toBeTrue();
    });
});
