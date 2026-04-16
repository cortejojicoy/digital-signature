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
