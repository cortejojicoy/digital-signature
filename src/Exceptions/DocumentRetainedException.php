<?php

namespace Kukux\DigitalSignature\Exceptions;

/**
 * Refuses to delete a record whose document has been routed for signatures.
 * Its signed versions are a history its signatories are entitled to. Bind a
 * DocumentOfRecordGate whose canDelete() allows it if your app must.
 */
class DocumentRetainedException extends \RuntimeException
{
}
