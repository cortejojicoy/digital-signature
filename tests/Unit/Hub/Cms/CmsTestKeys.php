<?php

namespace Kukux\DigitalSignature\Tests\Unit\Hub\Cms;

use Symfony\Component\Process\Process;

/**
 * Throwaway signing identities for the CMS and deferred-signing tests: a
 * small RSA CA, and an RSA and an EC signer it issued. Generated once per
 * process — key generation is the slow part of these tests.
 */
final class CmsTestKeys
{
    private static ?array $ca = null;

    private static array $signers = [];

    private static string|false|null $openssl = null;

    /**
     * @return array{cert: string, key: string, chain: array<int, string>}
     */
    public static function rsa(): array
    {
        return self::$signers['rsa'] ??= self::issue([
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
            'private_key_bits' => 2048,
        ], 'RSA Signer');
    }

    /**
     * @return array{cert: string, key: string, chain: array<int, string>}
     */
    public static function ec(): array
    {
        return self::$signers['ec'] ??= self::issue([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name'       => 'prime256v1',
        ], 'EC Signer');
    }

    /**
     * An openssl binary whose `cms` command works, or null.
     */
    public static function openssl(): ?string
    {
        if (self::$openssl === null) {
            self::$openssl = false;

            foreach (['/opt/homebrew/bin/openssl', '/usr/local/bin/openssl', '/usr/bin/openssl'] as $candidate) {
                if (! is_executable($candidate)) {
                    continue;
                }

                $probe = new Process([$candidate, 'cms', '-help']);
                $probe->run();

                if (str_contains($probe->getOutput().$probe->getErrorOutput(), '-verify')) {
                    self::$openssl = $candidate;
                    break;
                }
            }
        }

        return self::$openssl ?: null;
    }

    /**
     * Run `openssl cms -verify` on a detached CMS over $content, without
     * chain validation (-noverify: the CA here is throwaway). Returns
     * [exit ok, combined output].
     *
     * @return array{0: bool, 1: string}
     */
    public static function opensslVerify(string $cmsDer, string $content): array
    {
        $dir = sys_get_temp_dir().'/cms-verify-'.bin2hex(random_bytes(4));
        mkdir($dir);

        try {
            file_put_contents("{$dir}/sig.der", $cmsDer);
            file_put_contents("{$dir}/content.bin", $content);

            $process = new Process([
                self::openssl(), 'cms', '-verify', '-binary', '-inform', 'DER',
                '-in', "{$dir}/sig.der", '-content', "{$dir}/content.bin",
                '-noverify', '-out', "{$dir}/out.bin",
            ]);
            $process->run();

            return [$process->isSuccessful(), $process->getOutput().$process->getErrorOutput()];
        } finally {
            array_map('unlink', glob("{$dir}/*") ?: []);
            @rmdir($dir);
        }
    }

    // -------------------------------------------------------------------------

    private static function ca(): array
    {
        if (self::$ca !== null) {
            return self::$ca;
        }

        $key  = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048]);
        $csr  = openssl_csr_new(['commonName' => 'Kukux Test CA', 'organizationName' => 'Kukux Tests'], $key, ['digest_alg' => 'sha256']);
        $cert = openssl_csr_sign($csr, null, $key, 30, ['digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));

        openssl_x509_export($cert, $certPem);

        return self::$ca = ['cert' => $certPem, 'key' => $key];
    }

    private static function issue(array $keyOptions, string $commonName): array
    {
        $ca  = self::ca();
        $key = openssl_pkey_new($keyOptions);
        $csr = openssl_csr_new(['commonName' => $commonName, 'organizationName' => 'Kukux Tests'], $key, ['digest_alg' => 'sha256']);

        // A large serial exercises multi-byte INTEGER handling.
        $cert = openssl_csr_sign($csr, $ca['cert'], $ca['key'], 30, ['digest_alg' => 'sha256'], PHP_INT_MAX - random_int(0, 1000));

        openssl_x509_export($cert, $certPem);
        openssl_pkey_export($key, $keyPem);

        return ['cert' => $certPem, 'key' => $keyPem, 'chain' => [$ca['cert']]];
    }
}
