# Hash-only (deferred) PDF signing

How a client app stamps a PDF, the hub signs it, and the document never
leaves the app. Plan: phase 0.1 and A7 in
[plans/signature-hub.md](../../plans/signature-hub.md); contract: section 5 of
[contracts.md](contracts.md).

The standalone drivers (`FpdiDriver`, `TcpdfDriver`) sign inside TCPDF's
`Output()` with a private key on the same server. In client mode the key is
at the hub, so signing is split in three:

```
app                                   hub
───                                   ───
prepare()  stamp, reserve, hash ──►   signDigest(digest)  → detached CMS
inject()   ◄── CMS ─────────────────
```

## The pieces

| Piece | Class | Bound to |
|---|---|---|
| Stamp + reserve + inject (app) | `Drivers\PdfSigners\DeferredPdfSigner` | `Contracts\DeferredPdfSigner` |
| Sign a digest (hub) | `Hub\Cms\CmsSigner` | `Contracts\DigestSigner` |
| RFC 3161 timestamp | `Hub\Cms\TimestampAuthority` | (autowired into `CmsSigner`) |
| Read a CMS back | `Hub\Cms\SignedDataReader` | — |
| DER encode / read | `Support\Der\Der`, `Support\Der\Element`, `Support\Der\X509` | — |

```php
use Kukux\DigitalSignature\Contracts\DeferredPdfSigner;
use Kukux\DigitalSignature\Contracts\DigestSigner;

// App: stamp and reserve. Paths are on signature.storage_disk.
$prepared = app(DeferredPdfSigner::class)->prepare(
    pdfPath:   'generated/ar/record-12.pdf',
    imagePath: 'app-performance/7c1e….png',
    position:  ['page' => 1, 'x' => 100, 'y' => 90, 'width' => 160, 'height' => 50],
    reason:    'Approved',
    caption:   ['Digitally signed', 'by Juan DelaCruz', 'Date: 2026.10.08'],
    imageDisk: 'rustfs',
);
// $prepared->digest is what goes to POST /signature/hub/api/v1/sign-requests.

// Hub: sign the digest with the person's certificate.
$cms = app(DigestSigner::class)->signDigest(
    digestHex:      $prepared->digest,
    certificatePem: $certPem,
    privateKey:     $keyPem,
    chainPem:       [$caPem],
    tsaUrl:         config('signature.tsa.url'),
);

// App, on sign_request.completed: put the CMS in.
$signedPath = app(DeferredPdfSigner::class)->inject($prepared->path, $cms);
```

## prepare()

1. Imports every page through FPDI and draws the stamp at each placement —
   the same code as `FpdiDriver` (`Concerns\StampsImportedPages`), so the two
   cannot disagree about where a signature lands.
2. Turns on TCPDF's signature layout without a key
   (`ReservedSignatureTcpdf::reserveSignature()`): a `/Sig` dictionary,
   `/SubFilter /adbe.pkcs7.detached`, DocMDP `P 2` (certifying; form fill and
   further signatures allowed — the same as `FpdiDriver`), and a
   `/Contents<000…>` placeholder.
3. Closes the document and fills in `/ByteRange` the way TCPDF's own
   `Output()` does — but stops before the signing step. No throwaway key is
   generated and nothing is signed locally.
4. Stores it at `{signed_docs_path}/pending/{uuid}.pdf` and returns
   `PreparedPdf{path, digest, byteRange, placeholderLength}` where
   `digest = sha256(bytes[0..b) . bytes[c..c+d))`.

DocMDP does not interfere with injection: it restricts changes made *after*
signing, and `/Contents` is the one region `/ByteRange` leaves out precisely
so the signature can be written there.

### Placeholder size

`DeferredPdfSigner::DEFAULT_PLACEHOLDER_LENGTH` = **32 768 hex characters**
(16 KiB of CMS). Measured: an RSA-2048 signer + one CA certificate + an RFC
3161 token with its TSA chain is ~4.4 KiB. An RSA-4096 signer with a
three-certificate chain and a timestamp should stay around 8–10 KiB
(estimated, not measured). TCPDF's own
default (11 742 hex = 5.8 KiB) is too small once a timestamp is added. The
cost is file size only. To change it, bind the class with another value:
`new DeferredPdfSigner(placeholderLength: 65536)`.

## signDigest()

`CmsSigner` builds the detached `ContentInfo(SignedData)` by hand — PHP's
`openssl_pkcs7_sign()` / `openssl_cms_sign()` insist on hashing the content
themselves — and uses OpenSSL only for the final RSA/ECDSA operation:

- `version 1`, `digestAlgorithms {sha256}`, `encapContentInfo {id-data}`
  with no content (detached);
- `certificates [0]`: the signer certificate and every certificate in
  `$chainPem` (one string may hold several PEM blocks);
- one `SignerInfo`: `IssuerAndSerialNumber` (copied byte-for-byte from the
  certificate), `sha256`, signed attributes `contentType` (id-data),
  `signingTime`, `messageDigest` (the digest), `signingCertificateV2`
  (sha256 of the certificate + issuer/serial), signed with
  `openssl_sign(DER(SET OF signedAttrs), …, OPENSSL_ALGO_SHA256)`;
- signature algorithm `rsaEncryption` for RSA keys, `ecdsa-with-SHA256` for
  EC keys;
- with a `tsaUrl`: a `TimeStampReq` over `sha256(signature value)` with a
  random nonce and `certReq`, POSTed as `application/timestamp-query`. The
  response must be `granted` (or `grantedWithMods`), for our hash, with our
  nonce; the token goes in as the `id-aa-signatureTimeStampToken` unsigned
  attribute. The TSA's own signature is not checked here (that needs its
  trust anchor; it is the PDF reader's job).

Bad input fails with `Hub\Cms\CmsException` and a message naming it: a digest
that is not 64 hex characters, a key that does not match the certificate, a
public key passed as the private key, an encrypted PEM, a key type other than
RSA/EC, a TSA refusal.

## inject()

1. Finds the signature whose `/ByteRange` describes the file (starts at 0,
   gap is exactly `<hex>`, second range ends at EOF) and checks the
   placeholder is still all zeros — a second inject is refused.
2. Refuses a CMS whose `messageDigest` is not this file's `/ByteRange`
   digest (say, the answer to an earlier `prepare()` of the same document);
   injected anyway, every reader would reject the PDF.
3. Writes `bin2hex($cms)` zero-padded to the exact placeholder length, or
   throws `LengthException` if it does not fit (prepare again with a larger
   placeholder).
4. Re-hashes the byte range to prove nothing outside `/Contents` moved, and
   stores the result as `{signed_docs_path}/{uuid}_signed_{time}_{rand}.pdf`.

The prepared file is left in place so `inject()` can be retried; deleting it
once the signed copy is recorded is the caller's job.

## How it was verified

The tests (`tests/Unit/Hub/Cms`, `tests/Unit/Drivers/DeferredPdfSignerTest.php`)
check every signature two independent ways, for both an RSA and an EC signer:

- **Pure PHP:** decode the SignedData, take the signed attributes exactly as
  transmitted (re-tagged as `SET`), `openssl_verify()` them against the
  embedded certificate, and compare `messageDigest` with the SHA-256 of the
  bytes `/ByteRange` names in the finished PDF.
- **OpenSSL:** `openssl cms -verify -binary -inform DER -content <bytes>
  -noverify` (skipped with a message if no `openssl` binary with `cms` is
  found in `/opt/homebrew/bin`, `/usr/local/bin` or `/usr/bin`).

During development the finished PDFs were also checked with
[pyHanko](https://pyhanko.readthedocs.io/)'s validator against the test CA:
"cryptographically sound", "covers the entire file", DocMDP OK, judged
**VALID** — for RSA, EC, and RSA with a real RFC 3161 token from
`openssl ts -reply` (TSA certificate trusted, token sound).

## Still to do by hand: Adobe Acrobat (phase 0 exit criterion)

The plan's exit criterion is that the result validates as PAdES **in Adobe
Acrobat**, which no automated test here can do. On a machine with Acrobat:

1. Sign a real document through the hub with a certificate from the
   production CA, with `SIGNATURE_TSA_URL` set.
2. Add the CA root under *Preferences → Signatures → Identities & Trusted
   Certificates* (or use an AATL certificate).
3. Open the PDF: the signature panel should show "Certified by …", "Document
   has not been modified", the timestamp as "Signature is timestamped", and
   the signing time from the TSA rather than the local clock.
4. Fill a form field and save: still valid (DocMDP P=2). Edit page content:
   reported as modified.
5. Try a 4096-bit key and a three-level chain to confirm the placeholder.

Known caveats to look at during that check:

- `/SubFilter` is `adbe.pkcs7.detached`, as with the existing drivers.
  Strict PAdES baseline (ETSI EN 319 142) wants `ETSI.CAdES.detached` **and**
  no `signingTime` signed attribute; the contract requires `signingTime`, so
  the two would have to change together. Both SubFilter names are 20 bytes,
  so the swap does not move any offset if it is ever needed.
- No LTV data (DSS/VRI, OCSP/CRL embedding) is added; Acrobat may show
  "validity unknown" for revocation unless it can fetch it online.
