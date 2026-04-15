<?php

namespace Kukux\DigitalSignature\Exceptions;

/**
 * Thrown when a submitted signature image matches one already stored under
 * a different user — the classic screenshot/copy-paste forgery pattern.
 */
class ForgedSignatureException extends \RuntimeException {}
