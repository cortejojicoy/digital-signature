<?php

namespace Kukux\DigitalSignature\Console\Install;

/**
 * What one install step did, and the lines to log under it.
 */
final class StepResult
{
    public const DONE = 'done';

    public const SKIPPED = 'skipped';

    public const MANUAL = 'manual';

    public const FAILED = 'failed';

    public const DRY_RUN = 'dry-run';

    /**
     * @param  list<string>  $details  Lines logged under the step.
     * @param  list<string>  $warnings Lines logged with a "!" marker.
     * @param  bool  $halt  Stop the install after this step.
     */
    private function __construct(
        public readonly string $status,
        public readonly array $details = [],
        public readonly array $warnings = [],
        public readonly bool $halt = false,
    ) {}

    /**
     * @param  list<string>  $details
     * @param  list<string>  $warnings
     */
    public static function done(array $details = [], array $warnings = []): self
    {
        return new self(self::DONE, $details, $warnings);
    }

    /** @param list<string> $warnings */
    public static function skipped(string $reason, array $warnings = []): self
    {
        return new self(self::SKIPPED, [$reason], $warnings);
    }

    /**
     * The step couldn't safely do it; $details tell the user what to do by hand.
     *
     * @param  list<string>  $details
     * @param  list<string>  $warnings
     */
    public static function manual(array $details, array $warnings = []): self
    {
        return new self(self::MANUAL, $details, $warnings);
    }

    /**
     * @param  list<string>  $details
     * @param  list<string>  $warnings
     */
    public static function failed(array $details, bool $halt = false, array $warnings = []): self
    {
        return new self(self::FAILED, $details, $warnings, $halt);
    }

    /**
     * --dry-run: what the step would have done.
     *
     * @param  list<string>  $details
     */
    public static function dryRun(array $details): self
    {
        return new self(self::DRY_RUN, $details);
    }

    public function failedStep(): bool
    {
        return $this->status === self::FAILED;
    }
}
