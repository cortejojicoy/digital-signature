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
    | Verification QR
    |--------------------------------------------------------------------------
    | A QR carrying the signer, the signature UUID and a verification URL is
    | stamped to the right of each visible signature. It needs about another
    | signature's width of clear space, so forms that place signatures side by
    | side should turn it off rather than have it overlap the next block.
    */
    'qr' => [
        'enabled' => env('SIGNATURE_QR_ENABLED', true),
    ],

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
    | Floating launcher
    |--------------------------------------------------------------------------
    | A floating button, pinned to a corner of every panel page, that opens a
    | slide-over with the documents waiting on the signed-in user and their
    | signature library. It exists because signing is an interruption, not a
    | destination: a signatory arrives on some other page, and the work that
    | needs them should come to that page rather than make them find a sidebar
    | item under whatever navigation group the host app happened to choose.
    |
    | replaces_navigation: with the launcher on, the inbox page and the
    |   Signatures resource stop registering sidebar/topbar items — the
    |   launcher is the entry point and two of them is clutter. Both pages stay
    |   routable, and the slide-over links to them. Set this to false to have
    |   the launcher AND the navigation items.
    |
    | poll_seconds: how often the badge count refreshes while a page is open.
    |   0 disables polling (the count is then only as fresh as the page).
    |
    | color: the button's background. Null uses a neutral that reads on light
    |   and dark themes; set a brand hex to match your panel. This is a plain
    |   hex rather than a Filament color token because the CSS custom-property
    |   format for those changed between Filament majors, and a launcher that
    |   renders invisible on one of the three supported versions is worse than
    |   one that isn't brand-coloured by default.
    |
    | avoid_overlap: a plugin does not own the corner it is dropped into. Host
    |   apps put chat widgets, cookie bars, "back to top" buttons and their own
    |   FABs in exactly the same place, and a package that plants itself on top
    |   of one is a package that gets uninstalled. With this on, the button
    |   measures what is already pinned in its corner when the page loads and
    |   stacks itself clear of it, re-measuring when the viewport changes or
    |   another widget mounts late. Turn it off only if the probing itself
    |   causes trouble; prefer `offset` to place the button by hand.
    |
    | offset: distance from the corner before any stacking. Any CSS length.
    |
    | gap: pixels left between the button and whatever it stacks above.
    |
    | z_index: the button's layer. The slide-over sits one above it and its
    |   backdrop one below. Raise it if a host overlay covers the button, lower
    |   it if the button covers something that matters more.
    |
    | avoid / ignore: escape hatches for the detector, as CSS selectors.
    |   `avoid` always treats a match as occupying the corner (for widgets that
    |   mount in an iframe or after a long delay); `ignore` never does (for
    |   full-width toast rails and other decorative fixed elements that the
    |   size heuristics do not already rule out).
    */
    'launcher' => [
        'enabled'             => env('SIGNATURE_LAUNCHER_ENABLED', true),
        'replaces_navigation' => env('SIGNATURE_LAUNCHER_REPLACES_NAV', true),
        'position'            => env('SIGNATURE_LAUNCHER_POSITION', 'bottom-right'),
        'icon'                => env('SIGNATURE_LAUNCHER_ICON', 'heroicon-o-pencil-square'),
        'label'               => env('SIGNATURE_LAUNCHER_LABEL', 'Signatures'),
        'color'               => env('SIGNATURE_LAUNCHER_COLOR'),
        'poll_seconds'        => env('SIGNATURE_LAUNCHER_POLL', 60),
        'hide_when_empty'     => env('SIGNATURE_LAUNCHER_HIDE_WHEN_EMPTY', false),

        'avoid_overlap'       => env('SIGNATURE_LAUNCHER_AVOID_OVERLAP', true),
        'offset'              => [
            'x' => env('SIGNATURE_LAUNCHER_OFFSET_X', '1.5rem'),
            'y' => env('SIGNATURE_LAUNCHER_OFFSET_Y', '1.5rem'),
        ],
        'gap'                 => env('SIGNATURE_LAUNCHER_GAP', 12),
        'z_index'             => env('SIGNATURE_LAUNCHER_Z_INDEX', 40),

        /** @var array<int, string> Selectors always treated as occupying the corner. */
        'avoid'  => [],

        /** @var array<int, string> Selectors never treated as occupying the corner. */
        'ignore' => [],
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
