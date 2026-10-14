<?php

use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Kukux\DigitalSignature\Security\CertificateRevocationChecker;
use Kukux\DigitalSignature\Security\CrlValidator;
use Kukux\DigitalSignature\Security\OcspChecker;
use Kukux\DigitalSignature\Tests\Support\TestCertificates;

/*
 * Revocation for agents' imported certificates: OCSP through `openssl ocsp`
 * (faked with Process::fake), CRLs through CrlValidator, and the order
 * CertificateRevocationChecker asks them in. No network.
 */

/** `openssl ocsp` answering $status for whatever certificate file it was given. */
function fakeOcspResponder(string $status, bool $verified = true): void
{
    Process::fake(function (PendingProcess $process) use ($status, $verified) {
        $command = (array) $process->command;
        $cert = $command[array_search('-cert', $command, true) + 1];

        return Process::result(
            output: "{$cert}: {$status}\n\tThis Update: Oct 13 05:26:23 2026 GMT\n",
            errorOutput: $verified ? "Response verify OK\n" : "Response Verify Failure\n",
        );
    });
}

/** A CrlValidator whose CRL downloads answer from $lists (url => serials, or null for "unreachable"). */
function fakeCrls(array $lists): CrlValidator
{
    return new class($lists) extends CrlValidator
    {
        public int $fetched = 0;

        public function __construct(private array $lists) {}

        protected function fetchRevokedSerials(string $url, ?string $issuerPem = null): ?array
        {
            $this->fetched++;

            return $this->lists[$url] ?? null;
        }
    };
}

describe('OCSP', function () {

    it('reads a verified answer, and caches it', function () {
        $pki = TestCertificates::pki();
        fakeOcspResponder('revoked');

        $ocsp = new OcspChecker;

        expect($ocsp->status($pki['leaf']['pem'], $pki['intermediate']['pem'], [$pki['intermediate']['pem'], $pki['root']['pem']]))->toBe('revoked')
            ->and($ocsp->status($pki['leaf']['pem'], $pki['intermediate']['pem']))->toBe('revoked');

        Process::assertRanTimes(fn (PendingProcess $p) => in_array('http://ocsp.test.invalid', (array) $p->command, true), 1);
    });

    it("has no answer when the response doesn't verify, or says unknown", function () {
        $pki = TestCertificates::pki();
        $ocsp = new OcspChecker;

        fakeOcspResponder('good', verified: false);
        expect($ocsp->status($pki['leaf']['pem'], $pki['intermediate']['pem']))->toBeNull();

        fakeOcspResponder('unknown');
        expect($ocsp->status($pki['second']['pem'], $pki['intermediate']['pem']))->toBeNull();
    });

    it('asks nobody for a certificate without an OCSP URL', function () {
        $pki = TestCertificates::pki();
        fakeOcspResponder('good');

        // The CAs have no AIA extension.
        expect((new OcspChecker)->status($pki['intermediate']['pem'], $pki['root']['pem']))->toBeNull();
        Process::assertNothingRan();
    });

    it('has no answer when the responder is unreachable', function () {
        $pki = TestCertificates::pki();
        Process::fake(fn () => Process::result(errorOutput: 'Error querying OCSP responder', exitCode: 1));

        expect((new OcspChecker)->status($pki['p256']['pem'], $pki['intermediate']['pem']))->toBeNull();
    });
});

describe('CRL', function () {

    it('tells revoked, good and unreachable apart', function () {
        $pki = TestCertificates::pki();
        $serial = strtolower(openssl_x509_parse($pki['leaf']['cert'])['serialNumberHex']);
        $url = 'http://crl.test.invalid/signing-ca.crl';

        $status = function (array $lists, string $pem, string $issuerPem): string {
            Cache::flush();

            return fakeCrls($lists)->status($pem, $issuerPem);
        };

        expect($status([$url => [$serial]], $pki['leaf']['pem'], $pki['intermediate']['pem']))->toBe('revoked')
            ->and($status([$url => ['ff']], $pki['leaf']['pem'], $pki['intermediate']['pem']))->toBe('good')
            ->and($status([], $pki['leaf']['pem'], $pki['intermediate']['pem']))->toBe('unknown')
            // No CRL distribution point at all.
            ->and($status([$url => []], $pki['intermediate']['pem'], $pki['root']['pem']))->toBe('unknown');
    });

    it("doesn't cache an outage as \"not revoked\"", function () {
        $pki = TestCertificates::pki();
        $crls = fakeCrls([]);

        $crls->status($pki['leaf']['pem'], $pki['intermediate']['pem']);
        $crls->status($pki['leaf']['pem'], $pki['intermediate']['pem']);

        expect($crls->fetched)->toBe(2);
    });

    it('only believes a CRL its issuer signed', function () {
        exec('command -v openssl', $out, $code);

        if ($code !== 0) {
            $this->markTestSkipped('needs the openssl CLI');
        }

        $pki = TestCertificates::pki();
        $dir = sys_get_temp_dir().'/dsig_crl_'.bin2hex(random_bytes(4));
        mkdir($dir);
        touch("{$dir}/index.txt");
        file_put_contents("{$dir}/crlnumber", "01\n");
        file_put_contents("{$dir}/ca.cnf", "[ca]\ndefault_ca = d\n[d]\ndatabase = {$dir}/index.txt\ncrlnumber = {$dir}/crlnumber\ndefault_md = sha256\ndefault_crl_days = 30\n");
        file_put_contents("{$dir}/ca.pem", $pki['intermediate']['pem']);
        openssl_pkey_export($pki['intermediate']['key'], $key);
        file_put_contents("{$dir}/ca.key", $key);
        file_put_contents("{$dir}/leaf.pem", $pki['leaf']['pem']);

        $ca = sprintf('openssl ca -config %s -keyfile %s -cert %s', escapeshellarg("{$dir}/ca.cnf"), escapeshellarg("{$dir}/ca.key"), escapeshellarg("{$dir}/ca.pem"));
        exec("{$ca} -revoke ".escapeshellarg("{$dir}/leaf.pem").' 2>&1');
        exec("{$ca} -gencrl -out ".escapeshellarg("{$dir}/ca.crl").' 2>&1');

        $crls = new class extends CrlValidator
        {
            public function fetch(string $path, ?string $issuerPem): ?array
            {
                return $this->fetchRevokedSerials($path, $issuerPem);
            }
        };

        $serial = strtolower(openssl_x509_parse($pki['leaf']['cert'])['serialNumberHex']);

        expect($crls->fetch("{$dir}/ca.crl", $pki['intermediate']['pem']))->toContain($serial)
            ->and($crls->fetch("{$dir}/ca.crl", $pki['other_root']['pem']))->toBeNull()
            ->and($crls->fetch("{$dir}/missing.crl", $pki['intermediate']['pem']))->toBeNull();

        array_map('unlink', glob("{$dir}/*"));
        rmdir($dir);
    });
});

describe('the order of asking', function () {

    it('asks OCSP first, then the CRL when OCSP has no answer', function () {
        $pki = TestCertificates::pki();
        $url = 'http://crl.test.invalid/signing-ca.crl';
        $serial = strtolower(openssl_x509_parse($pki['leaf']['cert'])['serialNumberHex']);

        fakeOcspResponder('good');
        $crls = fakeCrls([$url => [$serial]]);
        expect((new CertificateRevocationChecker(new OcspChecker, $crls))->check($pki['leaf']['pem'], $pki['intermediate']['pem']))->toBe('good')
            ->and($crls->fetched)->toBe(0);

        Process::fake(fn () => Process::result(exitCode: 1));
        expect((new CertificateRevocationChecker(new OcspChecker, $crls))->check($pki['second']['pem'], $pki['intermediate']['pem']))->toBe('good')
            ->and($crls->fetched)->toBe(1);

        config(['signature.devices.pkcs12.revocation' => 'crl']);
        expect((new CertificateRevocationChecker(new OcspChecker, $crls))->check($pki['leaf']['pem'], $pki['intermediate']['pem']))->toBe('revoked');

        config(['signature.devices.pkcs12.revocation' => 'off']);
        expect((new CertificateRevocationChecker(new OcspChecker, fakeCrls([$url => [$serial]])))->check($pki['leaf']['pem'], $pki['intermediate']['pem']))->toBe('unknown');
    });
});
