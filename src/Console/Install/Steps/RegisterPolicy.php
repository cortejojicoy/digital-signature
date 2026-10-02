<?php

namespace Kukux\DigitalSignature\Console\Install\Steps;

use Illuminate\Support\Facades\Gate;
use Kukux\DigitalSignature\Console\Install\InstallContext;
use Kukux\DigitalSignature\Console\Install\InstallStep;
use Kukux\DigitalSignature\Console\Install\PhpFileEditor;
use Kukux\DigitalSignature\Console\Install\StepResult;
use Kukux\DigitalSignature\Models\Signature;

/**
 * Writes an owner-only policy for the package's Signature model and registers
 * it in AppServiceProvider. The model lives in vendor/, so Laravel's policy
 * discovery never finds one on its own.
 */
class RegisterPolicy implements InstallStep
{
    public const CLASS_NAME = 'DigitalSignaturePolicy';

    public function label(): string
    {
        return 'Registering policy';
    }

    public function summary(): string
    {
        return 'register a signature policy';
    }

    public function enabled(InstallContext $context): bool
    {
        return ! $context->option('no-policy');
    }

    public function run(InstallContext $context): StepResult
    {
        $existing = Gate::getPolicyFor(Signature::class);

        if ($existing !== null) {
            return StepResult::skipped('a policy is already registered: '.$existing::class);
        }

        $namespace = $this->appNamespace($context);
        $policyClass = $namespace.'Policies\\'.self::CLASS_NAME;
        $policyPath = $context->path('app/Policies/'.self::CLASS_NAME.'.php');
        $providerPath = $context->path('app/Providers/AppServiceProvider.php');
        $line = '\\Illuminate\\Support\\Facades\\Gate::policy(\\'.Signature::class.'::class, \\'.$policyClass.'::class);';

        if ($context->dryRun()) {
            return StepResult::dryRun(array_filter([
                is_file($policyPath) ? null : 'would write '.$context->relative($policyPath),
                'would register it in '.$context->relative($providerPath),
            ]));
        }

        $details = [];

        if (! is_file($policyPath)) {
            if (! is_dir(dirname($policyPath))) {
                mkdir(dirname($policyPath), 0755, true);
            }

            file_put_contents($policyPath, str_replace(
                '{{ namespace }}',
                $namespace.'Policies',
                (string) file_get_contents(dirname(__DIR__, 4).'/stubs/'.self::CLASS_NAME.'.php.stub'),
            ));

            $details[] = $context->relative($policyPath);
        }

        $manual = [
            'Register it in AppServiceProvider::boot():',
            '    '.$line,
        ];

        if (! is_file($providerPath)) {
            return StepResult::manual(array_merge($details, ['No app/Providers/AppServiceProvider.php.'], $manual));
        }

        $source = (string) file_get_contents($providerPath);

        if (str_contains($source, self::CLASS_NAME)) {
            return StepResult::skipped('already registered in '.$context->relative($providerPath));
        }

        $updated = $this->edit($source, $line);

        if ($updated === null) {
            return StepResult::manual(array_merge($details, ['Couldn\'t find AppServiceProvider::boot() to edit.'], $manual));
        }

        $result = (new PhpFileEditor($context))->write($providerPath, $updated);

        if ($result !== PhpFileEditor::WRITTEN) {
            return StepResult::manual(array_merge($details, $manual));
        }

        $details[] = 'registered in '.$context->relative($providerPath);

        return StepResult::done($details);
    }

    /**
     * AppServiceProvider with the Gate::policy() line as the first statement
     * of boot(), or null if there isn't exactly one boot() to put it in.
     */
    public function edit(string $source, string $line): ?string
    {
        $pattern = '/\n([ \t]*)(public function boot\(\)\s*(?::\s*void)?\s*\{)/';

        if (preg_match_all($pattern, $source) !== 1) {
            return null;
        }

        // Right after the opening brace, one level in from the method.
        return preg_replace_callback(
            $pattern,
            fn (array $m) => "\n".$m[1].$m[2]."\n".$m[1].'    '.$line,
            $source,
            1,
        );
    }

    protected function appNamespace(InstallContext $context): string
    {
        $composer = $context->path('composer.json');

        if (is_file($composer)) {
            $psr4 = json_decode((string) file_get_contents($composer), true)['autoload']['psr-4'] ?? [];

            foreach ($psr4 as $namespace => $paths) {
                foreach ((array) $paths as $path) {
                    if (realpath($context->path($path)) === realpath($context->path('app'))) {
                        return $namespace;
                    }
                }
            }
        }

        return 'App\\';
    }
}
