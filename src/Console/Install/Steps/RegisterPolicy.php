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
        $namespace = $this->appNamespace($context);

        // Read the registration, don't resolve it: resolving instantiates the
        // policy, which throws if the app registered a class that's gone.
        $registered = $this->registeredPolicy();

        if ($registered !== null) {
            return class_exists($registered)
                ? StepResult::skipped("a policy is already registered: {$registered}")
                : $this->restoreMissingPolicy($context, $registered, $namespace);
        }

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

            $this->writePolicy($policyPath, $namespace.'Policies', self::CLASS_NAME);

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
     * The app registers a policy class that can't be loaded, so every
     * authorization check on a signature would throw. Write the class where
     * its name says it lives, if that's inside the app and the file is gone.
     */
    protected function restoreMissingPolicy(InstallContext $context, string $class, string $namespace): StepResult
    {
        $problem = "A policy is registered for signatures ({$class}), but that class doesn't exist.";

        if (! str_starts_with($class, $namespace)) {
            return StepResult::manual([$problem, 'It\'s outside your app namespace, so create it yourself or remove the Gate::policy() line.']);
        }

        $path = $context->path('app/'.str_replace('\\', '/', substr($class, strlen($namespace))).'.php');
        $relative = $context->relative($path);

        if (is_file($path)) {
            return StepResult::manual([
                $problem,
                "{$relative} exists but isn't autoloaded. Run: composer dump-autoload",
            ]);
        }

        if ($context->dryRun()) {
            return StepResult::dryRun([$problem, "would write {$relative}"]);
        }

        $this->writePolicy($path, substr($class, 0, (int) strrpos($class, '\\')), substr($class, (int) strrpos($class, '\\') + 1));

        return StepResult::done([$relative], [
            $problem.' Wrote it from the package\'s owner-only policy.',
        ]);
    }

    protected function writePolicy(string $path, string $namespace, string $class): void
    {
        if (! is_dir(dirname($path))) {
            mkdir(dirname($path), 0755, true);
        }

        file_put_contents($path, str_replace(
            ['{{ namespace }}', 'class '.self::CLASS_NAME],
            [$namespace, 'class '.$class],
            (string) file_get_contents(dirname(__DIR__, 4).'/stubs/'.self::CLASS_NAME.'.php.stub'),
        ));
    }

    /** The policy class registered for Signature, without resolving it. */
    protected function registeredPolicy(): ?string
    {
        foreach (Gate::policies() as $model => $policy) {
            if (ltrim((string) $model, '\\') === Signature::class) {
                return ltrim((string) $policy, '\\');
            }
        }

        return null;
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
