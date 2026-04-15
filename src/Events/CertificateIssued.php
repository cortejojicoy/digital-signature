<?php

namespace Kukux\DigitalSignature\Events;

use Kukux\DigitalSignature\Models\UserCertificate;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CertificateIssued
{
    use Dispatchable, SerializesModels;
    public function __construct(public readonly UserCertificate $certificate) {}
}
