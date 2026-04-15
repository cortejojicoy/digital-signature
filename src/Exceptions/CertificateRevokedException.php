<?php

namespace Kukux\DigitalSignature\Exceptions;

/**
 * Thrown when a certificate's serial number appears in a downloaded CRL,
 * meaning the certificate was revoked before the signing attempt.
 */
class CertificateRevokedException extends \RuntimeException {}
