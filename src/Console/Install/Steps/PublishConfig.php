<?php

namespace Kukux\DigitalSignature\Console\Install\Steps;

use Illuminate\Support\Arr;
use Kukux\DigitalSignature\Console\Install\InstallContext;
use Kukux\DigitalSignature\Console\Install\InstallStep;
use Kukux\DigitalSignature\Console\Install\StepResult;

class PublishConfig implements InstallStep
{
    /** Blocks whose contents are the app's own, not package settings. */
    protected const APP_OWNED = ['templates'];

    public function label(): string
    {
        return 'Publishing config';
    }

    public function summary(): string
    {
        return 'publish config/signature.php';
    }

    public function enabled(InstallContext $context): bool
    {
        return true;
    }

    public function run(InstallContext $context): StepResult
    {
        $target = $context->path('config/signature.php');
        $source = self::packageConfigPath();
        $relative = $context->relative($target);

        if (is_file($target) && ! $context->force()) {
            $stale = $this->missingKeys(self::load($source), self::load($target));

            if ($stale === []) {
                return StepResult::skipped("{$relative} already exists and is current");
            }

            // Laravel merges this file one level deep: a block that's here
            // replaces the package's whole block, new keys included.
            return StepResult::skipped("{$relative} already exists (left as is)", array_merge(
                ['Your copy is missing keys a newer release added, and Laravel won\'t fill them in from the package:'],
                array_map(fn (string $key) => "    {$key}", $stale),
                ['Copy them from vendor/kukux/digital-signature/config/signature.php, or rerun with --force to replace the file.'],
            ));
        }

        if ($context->dryRun()) {
            return StepResult::dryRun(['would copy the package config to '.$relative]);
        }

        if (! is_dir(dirname($target))) {
            mkdir(dirname($target), 0755, true);
        }

        copy($source, $target);

        return StepResult::done([$relative]);
    }

    public static function packageConfigPath(): string
    {
        return dirname(__DIR__, 4).'/config/signature.php';
    }

    /**
     * Dotted keys the package has that the app's copy lacks, for every block
     * the app's copy kept. A block the app deleted entirely is fine: Laravel
     * falls back to the package's whole block.
     *
     * @param  array<mixed>  $package
     * @param  array<mixed>  $app
     * @return list<string>
     */
    public function missingKeys(array $package, array $app, string $prefix = ''): array
    {
        $missing = [];

        foreach ($package as $key => $value) {
            if (! is_string($key) || in_array($prefix.$key, self::APP_OWNED, true)) {
                continue;
            }

            if (! array_key_exists($key, $app)) {
                // Missing at the top level merges back in; nested, it's lost.
                if ($prefix !== '') {
                    $missing[] = $prefix.$key;
                }

                continue;
            }

            if (is_array($value) && Arr::isAssoc($value) && is_array($app[$key])) {
                array_push($missing, ...$this->missingKeys($value, $app[$key], $prefix.$key.'.'));
            }
        }

        return $missing;
    }

    /** @return array<mixed> */
    protected static function load(string $path): array
    {
        $config = (static fn () => require $path)();

        return is_array($config) ? $config : [];
    }
}
