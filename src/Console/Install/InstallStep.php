<?php

namespace Kukux\DigitalSignature\Console\Install;

interface InstallStep
{
    /** The log line, e.g. "Publishing config". */
    public function label(): string;

    /** What the intro list says this step will do. */
    public function summary(): string;

    /** False when a flag turned the step off; it is then left out entirely. */
    public function enabled(InstallContext $context): bool;

    public function run(InstallContext $context): StepResult;
}
