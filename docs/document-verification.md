# Verifying a signed PDF

`GET /signature/verify` is a public "drop a PDF here" page. Anyone holding a
PDF, such as a receiving office or an auditor, can check that it is a copy this
system issued, see how far signing got, and see who signed each slot and when.
The file never leaves their computer. Kukux Sign Agent uses the same lookup
for its **Verify PDF** feature.

It sits next to the QR page (`/signature/verify/{uuid}`). That page vouches for
one mark on a printout; this one vouches for a whole file.

## Why two checks

Progressive signing rebuilds the PDF on every pass, so a finished file carries
only the **last** signer's PKCS#7. The earlier signers are stamps in the file,
backed by rows in the database. A check of the file alone proves one signature
out of four, so the page does both:

| Check | Runs | Proves |
|---|---|---|
| Local | In the browser (`resources/js/verify/pdfSignature.js`, PKI.js) | The last signature is intact, covers these bytes, and chains to this system's CA |
| Record | Server, by fingerprint (`POST /signature/verify/lookup`) | These exact bytes are a version this system issued, and which slots that version carries |

Only SHA-256 fingerprints are sent:

- `sha256`: the whole file.
- `signed_revision_sha256`: the file cut at the end of its last signature's
  `/ByteRange`. It differs from `sha256` only when bytes were added after
  signing (an incremental save). The cut-off copy is exactly what was signed,
  so its fingerprint still finds the version even though the whole file no
  longer does.

Nothing new is stored for this. Every version already has a fingerprint:
`digital_signing_sessions.base_document_hash` (version 0) and each signature's
`signed_document_hash` (versions 1..n).

## Verdicts

| Verdict | Meaning |
|---|---|
| `complete` | The last version of a session every signatory finished |
| `in_progress` | The latest version, but slots are still unsigned |
| `superseded` | A real, earlier version; later signatures exist |
| `withdrawn` | The last version of a cancelled or expired session |
| `signature_valid_file_changed` | Not a version, but its signed prefix is: edited after signing |
| `not_issued` | Neither fingerprint matches anything issued here |

The page adds two of its own from the local check (`resources/js/verify/verdict.js`):
`invalid_signature` when the embedded signature doesn't match the file, and
`lookup_failed` when the server couldn't be asked. An exact record match
always wins. A failed lookup is never shown as `not_issued`.

Each slot is `signed`, `signed_later` (signed in a later version, so not on
this copy), `declined` or `pending`. For a signed slot the answer includes the
signer's name, when they signed, and the certificate's status
(`valid` / `revoked` / `unknown` / `unchecked`).

## What it discloses

Only what the holder's copy already shows: the template's name, slot labels,
and for slots this copy carries, who signed and when. Not the record behind
it, not who is still to sign, not emails, not paths. A SHA-256 can't be
guessed, so nobody can ask about a file they don't have, and a miss looks
the same whether or not a fingerprint was ever seen.

## Endpoints

| Route | Purpose |
|---|---|
| `GET /signature/verify` | The page. `web` middleware, no auth |
| `POST /signature/verify/lookup` | `{"sha256", "signed_revision_sha256"?}` → `{verdict, issuer, document, slots}`. **Not** in `web`: no session or CSRF, so the agent can call it. `throttle:60,1` |
| `GET /signature/trust/ca.pem` | The CA the browser checks chains against: `openssl.ca_cert_path`, or in client mode the hub's copy (cached a day). 404 when there is none; the page then shows trust as "not checked" |
| `GET /signature/verify/assets/verify.js` | The page's bundle, served by the package so no `filament:assets` step can break it |

Turn it off with `SIGNATURE_VERIFY_DOCUMENT_LOOKUP=false`, or with the rest
of verification via `SIGNATURE_VERIFY_ENABLED=false`.

## At the hub

In hub mode the same endpoint answers from documents apps reported, so
`signature.uplb.edu.ph/signature/verify` (and the agent) can verify a PDF from
any app:

- **Client apps report** each routed document after every signing event
  (`Client\Jobs\ReportDocumentToHub`, queued, retried through an outage) to
  `PUT /signature/hub/api/v1/documents/{uuid}` (scope `sign`). The body is a
  `Verification\DocumentSnapshot` array: version fingerprints and who signed
  which slot. Never the PDF. Turn it off with
  `SIGNATURE_HUB_REPORT_DOCUMENTS=false`.
- **`signature:hub-sync-documents [--since=Y-m-d]`** backfills, and catches
  what no event covers: a session cancelled or expired, or a report whose
  retries ran out. Schedule it daily.
- **On the sync queue driver** the report runs inside the signing request; a
  hub outage there is logged, never thrown, so it can't fail a signature.
- **The hub's answer names the app that reported the document** as `issuer`.
  If two apps report the same fingerprint, the first report wins: the issuer
  reports at routing time, so a later claim can't take over its documents.
- Certificate status comes from the hub's own certificates.
- Admins see reported documents under **Documents** in the hub admin panel.

## Limits

- Paper and scans have no fingerprint. Print the QR (`/signature/verify/{uuid}`)
  for those.
- A record match proves the bytes came from this system. It doesn't prove the
  content is true; that is what the signatories attested to.
- At the hub, the answer is only as good as the reporting app.
