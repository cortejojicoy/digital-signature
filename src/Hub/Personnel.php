<?php

namespace Kukux\DigitalSignature\Hub;

/** One person from the HR registry, as the hub sees them. */
final class Personnel
{
    public function __construct(
        public readonly string $key,
        public readonly string $name,
        public readonly ?string $empNo = null,
        public readonly ?string $email = null,
        public readonly ?string $unit = null,
        public readonly ?string $position = null,
        public readonly bool $active = true,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key'      => $this->key,
            'name'     => $this->name,
            'emp_no'   => $this->empNo,
            'email'    => $this->email,
            'unit'     => $this->unit,
            'position' => $this->position,
            'active'   => $this->active,
        ];
    }
}
