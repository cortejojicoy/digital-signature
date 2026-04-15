<?php

namespace Kukux\DigitalSignature\Drivers\Certificates\Contracts;

interface CertificateDriver
{
    /** Issue a new leaf certificate. Returns ['pfx' => binary, 'fingerprint' => hex] */
    public function issue(string $commonName, string $password): array;

    /** Load and verify a PFX file. Returns the parsed cert resource. */
    public function load(string $pfxPath, string $password): mixed;

    /** Revoke a certificate by fingerprint. */
    public function revoke(string $fingerprint): void;
}