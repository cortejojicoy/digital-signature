<?php

use Kukux\DigitalSignature\Drivers\Certificates\OpenSslDriver;

describe('OpenSslDriver', function () {

    beforeEach(function () {
        $this->driver = new OpenSslDriver(config('signature.openssl'));
    });

    it('issues a PFX binary and a 64-char fingerprint', function () {
        $result = $this->driver->issue('test-cn', 'password123');

        expect($result)->toHaveKeys(['pfx', 'fingerprint'])
            ->and($result['pfx'])->toBeString()->not->toBeEmpty()
            ->and($result['fingerprint'])->toHaveLength(64);
    });

    it('can load back its own PFX', function () {
        $result  = $this->driver->issue('test-cn', 'password123');
        $tmpPath = tempnam(sys_get_temp_dir(), 'sig_test_');
        file_put_contents($tmpPath, $result['pfx']);

        $certData = $this->driver->load($tmpPath, 'password123');

        expect($certData)->toBeArray()
            ->toHaveKey('cert')
            ->toHaveKey('pkey');

        @unlink($tmpPath);
    });

    it('throws RuntimeException on wrong password', function () {
        $result  = $this->driver->issue('test-cn', 'correct');
        $tmpPath = tempnam(sys_get_temp_dir(), 'sig_test_');
        file_put_contents($tmpPath, $result['pfx']);

        expect(fn () => $this->driver->load($tmpPath, 'wrong'))
            ->toThrow(\RuntimeException::class);

        @unlink($tmpPath);
    });
});
