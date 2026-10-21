<?php

/**
 * Regenerates the real signed PDFs the JS verifier is tested against.
 *
 *   GENERATE_VERIFICATION_FIXTURES=1 vendor/bin/pest tests/Feature/Verification/VerificationFixturesTest.php
 *
 * Not run in the normal suite: the fixtures are committed, so the JS tests
 * (`npm run test:js`) can run without PHP, and regenerating on every run would
 * churn them in git. What it makes, in this folder:
 *
 *   ca.pem       the issuing CA (RSA, like the openssl driver's ca.crt)
 *   other-ca.pem a CA that issued nothing here, for the untrusted-root case
 *   base.pdf     the frozen, unsigned document (version 0)
 *   v1.pdf       base signed by "Ana Prepared" (version 1)
 *   v2.pdf       v1 signed by "Ben Accepted" (version 2), progressive like the AR
 *   hashes.json  sha256 of each, plus each signer's name and slot
 *
 * Both signatures go through the package's own FpdiDriver, so this is the
 * byte layout TCPDF really writes, not a hand-made imitation of it.
 */

use Illuminate\Support\Facades\Storage;
use Kukux\DigitalSignature\Drivers\PdfSigners\FpdiDriver;

function verificationFixtureIssue(string $cn, ?array $issuer, bool $ca, string $config): array
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
    $options = ['config' => $config, 'x509_extensions' => $ca ? 'ca' : 'leaf', 'digest_alg' => 'sha256'];
    $csr = openssl_csr_new(['countryName' => 'PH', 'organizationName' => 'Kukux Verification Fixture', 'commonName' => $cn], $key, $options);
    $cert = openssl_csr_sign($csr, $issuer['cert'] ?? null, $issuer['key'] ?? $key, 3650 * 3, $options, random_int(1000, 999999));

    openssl_x509_export($cert, $pem);
    openssl_pkey_export($key, $keyPem);

    return ['cert' => $cert, 'key' => $key, 'pem' => $pem, 'key_pem' => $keyPem];
}

function generateVerificationFixtures(string $dir): void
{
    $config = tempnam(sys_get_temp_dir(), 'dsig_fixture_');
    file_put_contents($config, <<<'CNF'
        [req]
        distinguished_name = dn
        [dn]
        [ca]
        basicConstraints = critical,CA:TRUE
        keyUsage = critical,keyCertSign,cRLSign
        subjectKeyIdentifier = hash
        [leaf]
        basicConstraints = critical,CA:FALSE
        keyUsage = critical,digitalSignature,nonRepudiation
        CNF);

    $ca = verificationFixtureIssue('Kukux Fixture CA', null, true, $config);
    $other = verificationFixtureIssue('Somebody Else CA', null, true, $config);
    $ana = verificationFixtureIssue('Ana Prepared', $ca, false, $config);
    $ben = verificationFixtureIssue('Ben Accepted', $ca, false, $config);
    @unlink($config);

    $disk = Storage::disk(config('signature.storage_disk'));
    $disk->put('fixtures/base.pdf', minimalPdf());
    $disk->put('fixtures/sig.png', stampablePng());

    $driver = new FpdiDriver;
    $sign = fn (string $from, array $who, array $at) => $driver->sign(
        $from,
        'fixtures/sig.png',
        $at,
        ['cert' => $who['pem'], 'pkey' => $who['key_pem'], 'extracerts' => [$ca['pem']]],
        'Approved',
    );

    $v1 = $sign('fixtures/base.pdf', $ana, ['page' => 1, 'x' => 60, 'y' => 120, 'width' => 150, 'height' => 40]);
    $v2 = $sign($v1, $ben, ['page' => 1, 'x' => 320, 'y' => 120, 'width' => 150, 'height' => 40]);

    $files = ['base.pdf' => 'fixtures/base.pdf', 'v1.pdf' => $v1, 'v2.pdf' => $v2];
    $hashes = [];

    foreach ($files as $name => $path) {
        $bytes = $disk->get($path);
        file_put_contents("{$dir}/{$name}", $bytes);
        $hashes[$name] = hash('sha256', $bytes);
    }

    file_put_contents("{$dir}/ca.pem", $ca['pem']);
    file_put_contents("{$dir}/other-ca.pem", $other['pem']);
    file_put_contents("{$dir}/hashes.json", json_encode([
        'sha256'  => $hashes,
        'signers' => ['v1.pdf' => 'Ana Prepared', 'v2.pdf' => 'Ben Accepted'],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
}
