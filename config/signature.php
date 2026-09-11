<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Certificate driver
    |--------------------------------------------------------------------------
    | Supported: "openssl", "cfssl"
    */
    'cert_driver' => env('SIGNATURE_CERT_DRIVER', 'openssl'),

    'openssl' => [
        'digest_alg'       => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
        'cert_lifetime'    => 3650, // days
        'ca_cert_path'     => storage_path('app/certs/ca.crt'),
        'ca_key_path'      => storage_path('app/certs/ca.key'),
    ],

    'cfssl' => [
        'host'    => env('CFSSL_HOST', 'http://localhost:8888'),
        'profile' => env('CFSSL_PROFILE', 'client'),
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF signer driver
    |--------------------------------------------------------------------------
    | Supported: "fpdi", "tcpdf"
    */
    'pdf_driver' => env('SIGNATURE_PDF_DRIVER', 'fpdi'),

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    */
    'storage_disk'     => env('SIGNATURE_DISK', 'local'),
    'certs_path'       => 'certs',        // relative to disk root
    'signatures_path'  => 'signatures',   // raw signature images
    'signed_docs_path' => 'signed-docs',  // completed PDFs

    /*
    |--------------------------------------------------------------------------
    | Hashing
    |--------------------------------------------------------------------------
    */
    'hash_algo' => 'sha256',

    /*
    |--------------------------------------------------------------------------
    | Image constraints (client + server validated)
    |--------------------------------------------------------------------------
    */
    'image' => [
        'max_kb'        => 512,
        'allowed_mimes' => ['image/png', 'image/jpeg'],
        'canvas_width'  => 600,
        'canvas_height' => 200,
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue
    |--------------------------------------------------------------------------
    */
    'queue'            => env('SIGNATURE_QUEUE', 'default'),
    'queue_connection' => env('SIGNATURE_QUEUE_CONNECTION', null),

    /*
    |--------------------------------------------------------------------------
    | Timestamp Authority (TSA) — RFC 3161
    |--------------------------------------------------------------------------
    | When set, TCPDF will request a trusted timestamp from this endpoint and
    | embed it inside the PKCS#7 signature block.  This proves the document
    | was signed at a specific point in time, independently of the server clock.
    |
    | Leave null to disable.  Free public TSAs:
    |   https://freetsa.org/tsr
    |   http://timestamp.digicert.com
    */
    'tsa' => [
        'url' => env('SIGNATURE_TSA_URL', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF Templates
    |--------------------------------------------------------------------------
    | Host apps register signable PDF templates here. The plugin uses them
    | as targets in the placement designer and "apply signature to PDF" flows.
    |
    | Two registration styles — pick whichever fits:
    |
    | 1) Config-only (plug-and-play). Just point at a Blade view + declare
    |    the slots your signature lives in. Requires barryvdh/laravel-dompdf
    |    or a custom PdfRenderer.
    |
    |    'templates' => [
    |        'dtr' => [
    |            'label'         => 'Daily Time Record',
    |            'view'          => 'pdf.dtr',
    |            'sample_data'   => ['user' => ['name' => 'Sample User']],
    |            'data_resolver' => fn ($record) => ['record' => $record],
    |            'slots'         => ['employee', 'in_charge'],
    |            // or, with metadata:
    |            // 'slots' => [
    |            //     'employee'  => ['label' => 'Employee', 'required' => true],
    |            //     'in_charge' => ['label' => 'In Charge', 'required' => true],
    |            // ],
    |        ],
    |    ],
    |
    | 2) Full class. Implement PdfTemplate yourself when you need a custom
    |    renderer, conditional slots, or domain-aware sample data.
    |
    |    'templates' => [
    |        \App\Pdf\DtrTemplate::class,
    |    ],
    |
    | Both styles can be mixed in one array. You can also add templates at
    | runtime via SignaturePlugin::make()->templates([...]) or
    | app(PdfTemplateRegistry::class)->register(...).
    */
    'templates' => [
        //
    ],

    /*
    |--------------------------------------------------------------------------
    | Placement designer
    |--------------------------------------------------------------------------
    | The PDF Template Designer rasterizes the sample PDF at this DPI to
    | produce the page preview. Higher = sharper but slower and larger
    | cache files. 144 is a good balance for screen preview.
    */
    'designer' => [
        'dpi' => env('SIGNATURE_DESIGNER_DPI', 144),
    ],

    /*
    |--------------------------------------------------------------------------
    | Admin Resource
    |--------------------------------------------------------------------------
    | When enabled, a SignatureResource is automatically registered on the
    | Filament panel via SignaturePlugin.  The navigation appearance can be
    | changed here or overridden at runtime with the plugin's fluent API:
    |
    |   SignaturePlugin::make()
    |       ->navigationIcon('heroicon-o-pencil-square')
    |       ->navigationGroup('Documents')
    |       ->navigationSort(10)
    |
    | Set SIGNATURE_RESOURCE_ENABLED=false to hide the resource entirely.
    */
    'resource' => [
        'enabled'          => env('SIGNATURE_RESOURCE_ENABLED', true),
        'navigation_icon'  => env('SIGNATURE_RESOURCE_ICON', 'heroicon-o-pencil-square'),
        'navigation_group' => env('SIGNATURE_RESOURCE_GROUP', null),
        'navigation_sort'  => env('SIGNATURE_RESOURCE_SORT', null),
        'navigation_label' => env('SIGNATURE_RESOURCE_LABEL', 'Signatures'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Filament version
    |--------------------------------------------------------------------------
    | Normally detected from Composer's installed-versions manifest. Set this
    | only to force a specific branch (3, 4, or 5) — e.g. in a test suite that
    | needs to exercise the v3 component classes on a v5 install.
    */
    'filament_version' => env('SIGNATURE_FILAMENT_VERSION'),

    /*
    |--------------------------------------------------------------------------
    | Signing sessions (multi-signatory documents)
    |--------------------------------------------------------------------------
    | A session owns one document through N signatures. Opening one freezes
    | the rendered PDF so later signatories sign the same bytes the first one
    | did — without that, each signature would land on a fresh render and
    | erase the stamps before it.
    |
    | sequence_mode:
    |   'sequential' — signatories must sign in SlotDefinition::$order.
    |                  This is what "Prepared by → Attested by → Noted by" wants.
    |   'parallel'   — any assigned signatory may sign at any time.
    |
    | expires_after_days: sessions older than this stop accepting signatures.
    |   Null disables expiry.
    */
    'sessions' => [
        'sequence_mode'         => env('SIGNATURE_SEQUENCE_MODE', 'sequential'),
        'expires_after_days'    => env('SIGNATURE_SESSION_EXPIRY_DAYS'),
        'notification_channels' => ['mail'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-signature mode
    |--------------------------------------------------------------------------
    | How several signatures end up on one PDF.
    |
    | 'progressive' (default)
    |     Each signatory's signature is stamped onto the previous signatory's
    |     output and the document is re-signed with THEIR certificate. The
    |     finished PDF shows every visible signature, and the database holds a
    |     verifiable hash chain (signature N's document_hash == signature N-1's
    |     signed_document_hash). Caveat, stated plainly: because FPDI/TCPDF
    |     rebuild the file on every pass, only the MOST RECENT PKCS#7 block
    |     survives inside the PDF. Readers show one cryptographic signature,
    |     not N.
    |
    | 'incremental'
    |     True PAdES: each signature is appended as an incremental update and
    |     every earlier signature stays cryptographically valid, so a reader
    |     shows N distinct signers. Requires a driver implementing
    |     Kukux\DigitalSignature\Contracts\SupportsIncrementalSigning —
    |     neither bundled driver can, because FPDI rewrites the document. The
    |     session fails loudly rather than silently degrading.
    |
    | Pick 'incremental' when the legal requirement is N independently
    | verifiable signer certificates. 'progressive' is right when the bar is a
    | visible signature block plus tamper-evidence and a full audit trail.
    */
    'multi_signature' => [
        'mode' => env('SIGNATURE_MULTI_MODE', 'progressive'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-affix (consent model)
    |--------------------------------------------------------------------------
    | Whether a signature may be applied while its owner is not present in
    | the request. Automation can remove the effort of signing; it must never
    | remove the consent.
    |
    | 'approval' (default, safest)
    |     Never auto-signs. Routing still finds the right person and
    |     pre-places their signature, and the inbox brings the document to
    |     them — but the PKCS#7 block is produced in their own authenticated
    |     request. No new trust assumptions over single-signer signing.
    |
    | 'delegated'
    |     Auto-signs only where the signatory has created a SignatureDelegation
    |     covering this template and role. Grants are scoped, expiring,
    |     revocable, and can only be created by the grantor in their own
    |     session. Understand the trade: a standing grant means the server can
    |     sign as that user for the grant's lifetime.
    |
    | 'implicit'  — UNSAFE, off by default
    |     Treats being tagged on a record as consent to sign it. Anyone who can
    |     edit the record can then cause that person's certificate to sign it.
    |     Requires allow_implicit => true as a second, deliberate acknowledgement.
    |
    | notify: the signatory is told about every auto-affix. Leave this on.
    |     Silent signing is not acceptable even with consent.
    */
    'auto_affix' => [
        'mode'                  => env('SIGNATURE_AUTO_AFFIX_MODE', 'approval'),
        'allow_implicit'        => env('SIGNATURE_ALLOW_IMPLICIT_AFFIX', false),
        'notify'                => env('SIGNATURE_AUTO_AFFIX_NOTIFY', true),
        'default_grant_days'    => env('SIGNATURE_GRANT_DAYS', 365),
        'notification_channels' => ['mail'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Signature inbox
    |--------------------------------------------------------------------------
    | The "Awaiting my signature" page the plugin registers on each panel.
    | Set navigation => false to keep the page routable but out of the sidebar.
    */
    'inbox' => [
        'enabled'          => env('SIGNATURE_INBOX_ENABLED', true),
        'navigation'       => env('SIGNATURE_INBOX_NAV', true),
        'navigation_label' => env('SIGNATURE_INBOX_LABEL', 'Awaiting my signature'),
        'navigation_icon'  => env('SIGNATURE_INBOX_ICON', 'heroicon-o-inbox-arrow-down'),
        'navigation_group' => env('SIGNATURE_INBOX_GROUP'),
        'navigation_sort'  => env('SIGNATURE_INBOX_SORT'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Image Metadata (machine-binding security)
    |--------------------------------------------------------------------------
    | Every stored signature PNG receives four HMAC-signed tEXt chunks:
    |   Sig-User-Id, Sig-Machine-Hash, Sig-Timestamp, Sig-Hmac
    |
    | enforce_machine_lock: when true, re-uploading a signature image from a
    |   different browser or IP is rejected with MachineBindingException.
    |   When false (default), only the HMAC and user-id are checked.
    */
    'metadata' => [
        'enforce_machine_lock' => env('SIGNATURE_MACHINE_LOCK', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | CRL Validation (Certificate Revocation List)
    |--------------------------------------------------------------------------
    | When enabled, the CRL Distribution Points embedded in the signer's
    | certificate are downloaded and checked before every signing operation.
    | Revoked certificates are rejected with CertificateRevokedException.
    |
    | Requires the `openssl` CLI binary in PATH.
    | Self-signed dev certificates have no CDP and are silently skipped.
    |
    | cache_ttl_hours: how long a downloaded CRL is cached (default 24 h).
    */
    'crl' => [
        'enabled'         => env('SIGNATURE_CRL_ENABLED', false),
        'cache_ttl_hours' => 24,
    ],

];
