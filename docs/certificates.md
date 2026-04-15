# Certificates

The plugin issues one X.509 certificate per user. The certificate is stored as an encrypted PFX file on the configured storage disk and is reused across all signing requests until it expires or is revoked.

---

## How certificates are issued

When a user signs for the first time, `CertificateService::getOrCreate()` looks for a valid certificate. Finding none, it calls `CertificateService::issue()`, which:

1. Generates a 2048-bit RSA key pair
2. Creates a CSR with `commonName = user-{id}@{app-domain}`
3. Signs the CSR (self-signed by default, or CA-signed if `ca_cert_path` is configured)
4. Exports the result as a password-encrypted PFX file
5. Stores the PFX at `{certs_path}/{user_id}_{timestamp}.pfx`
6. Creates a `UserCertificate` record with the SHA-256 fingerprint

The `CertificateIssued` event is fired on first issuance.

---

## Using CertificateService directly

```php
use Kukux\DigitalSignature\Services\CertificateService;

$service = app(CertificateService::class);

// Get existing valid cert or create one
$cert = $service->getOrCreate($user->id, $password);

// Force a new certificate
$cert = $service->issue($user->id, $password);

// Load cert data (decrypted PEM strings)
$certData = $service->load($cert, $password);
// $certData['cert']  — PEM certificate string
// $certData['pkey']  — PEM private key (decrypted)

// Revoke
$service->revoke($cert);
```

---

## Certificate model

```php
use Kukux\DigitalSignature\Models\UserCertificate;

$cert = UserCertificate::where('user_id', $user->id)
    ->whereNull('revoked_at')
    ->latest()
    ->first();

$cert->isValid();    // not expired and not revoked
$cert->isExpired();  // expires_at is in the past
$cert->isRevoked();  // revoked_at is set

$cert->fingerprint;  // SHA-256 hex fingerprint
$cert->issued_at;    // Carbon
$cert->expires_at;   // Carbon
$cert->pfx_path;     // path on storage disk
```

---

## Using a CA instead of self-signed certs

Place your CA certificate and private key on the server:

```bash
storage/app/certs/ca.crt
storage/app/certs/ca.key
```

Update `config/signature.php`:

```php
'openssl' => [
    'ca_cert_path' => storage_path('app/certs/ca.crt'),
    'ca_key_path'  => storage_path('app/certs/ca.key'),
],
```

User certificates are now signed by your CA, which allows PDF readers to build a chain of trust to a known root.

---

## Certificate lifetime

Default is 3650 days (10 years). Change it in config:

```php
'openssl' => [
    'cert_lifetime' => 365,  // 1 year
],
```

When a certificate expires, `getOrCreate()` automatically issues a new one on the next signing attempt.

---

## Revoking a certificate

Revoking marks the `revoked_at` timestamp. The user's next signing request will issue a new certificate.

```php
use Kukux\DigitalSignature\Services\CertificateService;

app(CertificateService::class)->revoke($cert);
```

If CRL validation is enabled and your certificates include CRL Distribution Points, the revoked certificate will be rejected before signing. Self-signed certificates (no CDP) are not checked against a CRL.

---

## Using CFSSL

CFSSL is a PKI toolkit by Cloudflare. Set up a CFSSL server, then:

```bash
SIGNATURE_CERT_DRIVER=cfssl
CFSSL_HOST=http://your-cfssl-server:8888
CFSSL_PROFILE=client
```

The CFSSL driver calls `POST /api/v1/cfssl/newcert` and packages the response as a PFX.
