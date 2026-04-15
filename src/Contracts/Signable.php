<?php

namespace Kukux\DigitalSignature\Contracts;

interface Signable
{
    public function getSignableTitle(): string;
    public function getSignablePdfPath(): string;
    public function getSignableId(): int|string;
}
