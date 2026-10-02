<?php

namespace Kukux\DigitalSignature\Console\Install\Steps;

use Kukux\DigitalSignature\Console\Install\EnvFile;
use Kukux\DigitalSignature\Console\Install\InstallContext;
use Kukux\DigitalSignature\Console\Install\InstallStep;
use Kukux\DigitalSignature\Console\Install\StepResult;

/**
 * Certificates (encrypted PFX), signature images and signed PDFs all land on
 * SIGNATURE_DISK. On a public disk anyone with the URL could download them, so
 * this stops the install rather than migrate onto a leaking setup.
 */
class CheckStorage implements InstallStep
{
    public function label(): string
    {
        return 'Checking storage';
    }

    public function summary(): string
    {
        return 'check the signature disk is private';
    }

    public function enabled(InstallContext $context): bool
    {
        return true;
    }

    public function run(InstallContext $context): StepResult
    {
        // .env may have been written a moment ago, after config was loaded.
        $disk = (new EnvFile($context->path('.env')))->get('SIGNATURE_DISK')
            ?: (string) config('signature.storage_disk', 'local');

        $config = config("filesystems.disks.{$disk}");

        if (! is_array($config)) {
            return StepResult::failed([
                "SIGNATURE_DISK is \"{$disk}\", but config/filesystems.php has no such disk.",
                'Add the disk, or set SIGNATURE_DISK to a private one such as local.',
            ], halt: true);
        }

        if ($this->isPublic($disk, $config)) {
            return StepResult::failed([
                "SIGNATURE_DISK is \"{$disk}\", which is publicly readable.",
                'Certificates and signed PDFs would be downloadable by URL. Point it at a private disk such as local.',
            ], halt: true);
        }

        $root = isset($config['root']) ? ' ('.$context->relative((string) $config['root']).')' : '';

        return StepResult::done(["disk \"{$disk}\"{$root} is private"]);
    }

    /** @param  array<string, mixed>  $config */
    protected function isPublic(string $name, array $config): bool
    {
        if ($name === 'public' || ($config['visibility'] ?? null) === 'public') {
            return true;
        }

        $root = isset($config['root']) ? realpath((string) $config['root']) ?: (string) $config['root'] : null;
        $public = realpath(public_path()) ?: public_path();

        return $root !== null && str_starts_with($root, rtrim($public, '/').'/');
    }
}
