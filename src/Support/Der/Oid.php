<?php

namespace Kukux\DigitalSignature\Support\Der;

/**
 * The object identifiers the CMS and timestamp code uses, in one place so a
 * typo in a dotted string can only happen once.
 */
final class Oid
{
    // PKCS #7 / CMS content types (RFC 5652)
    public const DATA                       = '1.2.840.113549.1.7.1';
    public const SIGNED_DATA                = '1.2.840.113549.1.7.2';
    public const TST_INFO                   = '1.2.840.113549.1.9.16.1.4';

    // Signed attributes (RFC 5652 §11, RFC 5035)
    public const CONTENT_TYPE               = '1.2.840.113549.1.9.3';
    public const MESSAGE_DIGEST             = '1.2.840.113549.1.9.4';
    public const SIGNING_TIME               = '1.2.840.113549.1.9.5';
    public const SIGNING_CERTIFICATE_V2     = '1.2.840.113549.1.9.16.2.47';

    // Unsigned attribute (RFC 3161 appendix A)
    public const SIGNATURE_TIMESTAMP_TOKEN  = '1.2.840.113549.1.9.16.2.14';

    // Algorithms
    public const SHA256                     = '2.16.840.1.101.3.4.2.1';
    public const RSA_ENCRYPTION             = '1.2.840.113549.1.1.1';
    public const SHA256_WITH_RSA            = '1.2.840.113549.1.1.11';
    public const ECDSA_WITH_SHA256          = '1.2.840.10045.4.3.2';
}
