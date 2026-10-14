<?php

namespace Kukux\DigitalSignature\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;

/**
 * Asks a certificate's OCSP responder (its AIA OCSP URL) whether it's
 * revoked, through the `openssl ocsp` CLI, the same dependency CrlValidator
 * has. The response must verify: signed by the issuer, or by a responder the
 * issuer delegated to, chaining up to $trustPems.
 *
 * A definite answer is cached by issuer and serial for a few minutes, so a
 * refused pairing tried again doesn't ask the CA again.
 */
class OcspChecker
{
    private const CACHE_SECONDS = 300;

    private const TIMEOUT_SECONDS = 10;

    /**
     * @param  list<string>  $trustPems  The issuer up to the root.
     * @return 'good'|'revoked'|null  null: no OCSP URL, no answer, or an
     *                                answer that didn't verify or said `unknown`.
     */
    public function status(string $certPem, string $issuerPem, array $trustPems = []): ?string
    {
        $parsed = openssl_x509_parse($certPem);
        $url = is_array($parsed) ? self::url($parsed) : null;
        $serial = strtolower((string) ($parsed['serialNumberHex'] ?? ''));

        if ($url === null || $serial === '') {
            return null;
        }

        $key = 'sig_ocsp_'.hash('sha256', $url.'|'.openssl_x509_fingerprint($issuerPem, 'sha256').'|'.$serial);

        $cached = Cache::get($key);

        if ($cached === 'good' || $cached === 'revoked') {
            return $cached;
        }

        $status = $this->query($url, $certPem, $issuerPem, $trustPems);

        if ($status !== null) {
            Cache::put($key, $status, self::CACHE_SECONDS);
        }

        return $status;
    }

    /**
     * The first http(s) OCSP URL in the Authority Information Access extension.
     *
     * @param  array<string, mixed>  $parsed  openssl_x509_parse() output.
     */
    public static function url(array $parsed): ?string
    {
        $aia = (string) ($parsed['extensions']['authorityInfoAccess'] ?? '');

        return preg_match('/OCSP - URI:(https?:\/\/\S+)/i', $aia, $m) ? $m[1] : null;
    }

    /**
     * @param  list<string>  $trustPems
     * @return 'good'|'revoked'|null
     */
    protected function query(string $url, string $certPem, string $issuerPem, array $trustPems): ?string
    {
        $files = [];

        try {
            foreach (['cert' => $certPem, 'issuer' => $issuerPem, 'trust' => implode("\n", [$issuerPem, ...$trustPems])] as $name => $pem) {
                $files[$name] = (string) tempnam(sys_get_temp_dir(), 'sig_ocsp_');
                file_put_contents($files[$name], $pem);
            }

            $result = Process::timeout(self::TIMEOUT_SECONDS + 5)->run([
                'openssl', 'ocsp',
                '-issuer', $files['issuer'],
                '-cert', $files['cert'],
                '-url', $url,
                '-CAfile', $files['trust'],
                '-timeout', (string) self::TIMEOUT_SECONDS,
            ]);

            $output = $result->output()."\n".$result->errorOutput();

            if (! $result->successful() || ! str_contains($output, 'Response verify OK')) {
                return null;
            }

            // "<cert file>: good" / ": revoked" / ": unknown"
            return preg_match('/^'.preg_quote($files['cert'], '/').':\s*(good|revoked)\b/m', $output, $m) ? $m[1] : null;
        } catch (\Throwable) {
            return null;
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
    }
}
